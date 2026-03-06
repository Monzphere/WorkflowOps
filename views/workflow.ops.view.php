<?php declare(strict_types = 0);

$this->addJsFile('multilineinput.js');
$this->addJsFile('items.js');

require_once dirname(__FILE__).'/../../../include/defines.inc.php';
require_once dirname(__FILE__).'/../../../include/html.inc.php';
require_once dirname(__FILE__).'/../../../include/actions.inc.php';
require_once dirname(__FILE__).'/../../../include/items.inc.php';
require_once dirname(__FILE__).'/../../../include/classes/helpers/CMenuPopupHelper.php';

class CWorkflowRawHtml extends CObject {
	private $html = '';

	public function __construct(string $html) {
		parent::__construct();
		$this->html = $html;
	}

	public function toString($destroy = true) {
		return $this->html;
	}
}

$problems = $data['problems'] ?? [];
$triggers = $data['triggers'] ?? [];
$actions_by_event = $data['actions_by_event'] ?? [];
$users = $data['users'] ?? [];
$mediatypes = $data['mediatypes'] ?? [];
$hosts = $data['hosts'] ?? [];
$maintenances_by_event = $data['maintenances_by_event'] ?? [];
$maps_by_host = $data['maps_by_host'] ?? [];
$templates_by_host = $data['templates_by_host'] ?? [];
$proxy_by_id = $data['proxy_by_id'] ?? [];
$item_values_at_problem = $data['item_values_at_problem'] ?? [];
$item_values_at_resolution = $data['item_values_at_resolution'] ?? [];
$item_template_by_id = $data['item_template_by_id'] ?? [];
$trigger_template_by_id = $data['trigger_template_by_id'] ?? [];
$item_tags_by_id = $data['item_tags_by_id'] ?? [];
$triggers_by_item = $data['triggers_by_item'] ?? [];
$service_trees = $data['service_trees'] ?? [];
$problem_count_current = $data['problem_count_current'] ?? null;
$problem_count_previous = $data['problem_count_previous'] ?? null;
$is_single = $data['is_single'] ?? false;
$is_popup = $data['is_popup'] ?? false;

function flattenServiceChildren(array $children): array {
	$out = [];
	foreach ($children as $c) {
		$out[] = $c;
		if (!empty($c['children'])) {
			$out = array_merge($out, flattenServiceChildren($c['children']));
		}
	}
	return $out;
}

function getOrderedItemsFromTrigger(array $trigger): array {
	$items_by_id = [];
	foreach ($trigger['items'] as $it) {
		$items_by_id[$it['itemid']] = $it;
	}
	$ordered = !empty($trigger['functions'])
		? array_filter(array_map(fn($fn) => $items_by_id[$fn['itemid']] ?? null, $trigger['functions']))
		: $trigger['items'];
	$unique = [];
	foreach ($ordered as $item) {
		$id = $item['itemid'];
		if (!isset($unique[$id])) {
			$unique[$id] = $item;
		}
	}
	return array_values($unique);
}

function buildItemDetailBits(array $item, array $item_template_by_id): array {
	$bits = [];
	if (!empty($item['delay'])) {
		$bits[] = _('Interval').': '.$item['delay'];
	}
	if (!empty($item['key_'])) {
		$bits[] = _('Key').': '.$item['key_'];
	}
	if (isset($item['type'])) {
		$bits[] = item_type2str((int) $item['type']);
	}
	$bits[] = itemValueTypeString((int) ($item['value_type'] ?? 0));
	if (!empty($item['units'])) {
		$bits[] = $item['units'];
	}
	$tpl = $item_template_by_id[$item['itemid']] ?? null;
	if ($tpl !== null) {
		$bits[] = _('Template').': '.$tpl;
	}
	return $bits;
}

function makeTagsCompact(array $tags): ?CDiv {
	if (empty($tags)) {
		return null;
	}
	$lines = [];
	foreach ($tags as $tag) {
		$lines[] = ($tag['tag'] ?? '') . (isset($tag['value']) && $tag['value'] !== '' ? ': ' . $tag['value'] : '');
	}
	$count = count($tags);
	$wrap = (new CDiv())->addClass('mnz-workflow-card-tags-compact mnz-workflow-tags-trigger');
	$wrap->addItem((new CSpan())->addClass(ZBX_ICON_CIRCLE_INFO)->addClass('mnz-workflow-card-icon'));
	$wrap->addItem((new CSpan((string) $count))->addClass('mnz-workflow-tags-badge'));
	$wrap->setAttribute('data-tags-content', implode("\n", $lines));
	$wrap->setAttribute('title', _('Click to view tags'));
	$wrap->setAttribute('role', 'button');
	$wrap->setAttribute('tabindex', '0');
	return $wrap;
}

function collectServicesWithSli(array $service_trees, int $max = 2): array {
	$out = [];
	$seen = [];
	foreach ($service_trees as $tree) {
		$candidates = [];
		$imp = $tree['impacted_service'] ?? null;
		if ($imp && !empty($imp['has_sla']) && isset($imp['sli']) && is_numeric($imp['sli'])) {
			$candidates[] = $imp;
		}
		foreach ($tree['path_to_root'] ?? [] as $svc) {
			if (!empty($svc['has_sla']) && isset($svc['sli']) && is_numeric($svc['sli'])) {
				$candidates[] = $svc;
			}
		}
		foreach (flattenServiceChildren($tree['children_tree'] ?? []) as $svc) {
			if (!empty($svc['has_sla']) && isset($svc['sli']) && is_numeric($svc['sli'])) {
				$candidates[] = $svc;
			}
		}
		foreach ($candidates as $svc) {
			$sid = $svc['serviceid'] ?? ('n' . count($out));
			if (!isset($seen[$sid]) && count($out) < $max) {
				$seen[$sid] = true;
				$out[] = [
					'sli' => (float) $svc['sli'],
					'slo' => isset($svc['slo']) && is_numeric($svc['slo']) ? (float) $svc['slo'] : 99.9
				];
			}
		}
	}
	return $out;
}

function renderSliDonutSvg(?float $sli, float $slo = 99.9): string {
	$val = ($sli !== null && !is_nan($sli)) ? min(100.0, max(0.0, $sli)) : null;
	$sloNum = min(100.0, max(1.0, $slo));
	$sloLow = max(0.0, $sloNum - 5.0);
	$fillColor = '#6c757d';
	if ($val !== null) {
		if ($val < $sloLow) {
			$fillColor = '#e74c3c';
		} elseif ($val < $sloNum) {
			$fillColor = '#f1c40f';
		} else {
			$fillColor = '#27ae60';
		}
	}
	$pct = ($val !== null) ? $val / 100.0 : 0.0;
	$R = 45;
	$r = 27;
	$polar = function (float $cx, float $cy, float $rad, float $deg): string {
		$a = ($deg - 90) * M_PI / 180.0;
		return ($cx + $rad * cos($a)) . ',' . ($cy + $rad * sin($a));
	};
	$arcPath = function (float $r1, float $r2, float $a1, float $a2) use ($polar): string {
		$span = $a2 - $a1;
		if ($span <= 0) {
			$span += 360;
		}
		$p1 = $polar(50, 50, $r1, $a1);
		$p2 = $polar(50, 50, $r1, $a2);
		$p3 = $polar(50, 50, $r2, $a2);
		$p4 = $polar(50, 50, $r2, $a1);
		$big = $span >= 180 ? 1 : 0;
		return "M {$p1} A {$r1},{$r1} 0 {$big},1 {$p2} L {$p3} A {$r2},{$r2} 0 {$big},0 {$p4} Z";
	};
	$aR = 360.0 * ($sloLow / 100.0);
	$aY = 360.0 * ($sloNum / 100.0);
	if ($aR < 0.5) $aR = 0.5;
	if ($aY - $aR < 0.5) $aY = $aR + 0.5;
	if (360.0 - $aY < 0.5) $aY = 359.5;
	$parts = [];
	$parts[] = '<path d="' . $arcPath($R, $r, 0, $aR) . '" fill="#e74c3c" class="mnz-workflow-sli-donut-bg mnz-workflow-sli-donut-red"/>';
	$parts[] = '<path d="' . $arcPath($R, $r, $aR, $aY) . '" fill="#f1c40f" class="mnz-workflow-sli-donut-bg mnz-workflow-sli-donut-yellow"/>';
	$parts[] = '<path d="' . $arcPath($R, $r, $aY, 359.999) . '" fill="#27ae60" class="mnz-workflow-sli-donut-bg mnz-workflow-sli-donut-green"/>';
	if ($pct < 0.999) {
		$aStart = $pct <= 0.001 ? 0.001 : 360.0 * $pct;
		$parts[] = '<path d="' . $arcPath($R, $r, $aStart, 359.999) . '" fill="#5a6268" class="mnz-workflow-sli-donut-empty"/>';
	}
	$displayVal = ($val !== null) ? round($val, 1) . '%' : '-';
	$parts[] = '<text x="50" y="55" text-anchor="middle" font-size="13" font-weight="bold" fill="' . $fillColor . '" class="mnz-workflow-sli-donut-value">' . htmlspecialchars((string) $displayVal) . '</text>';
	return '<svg viewBox="0 0 100 100" class="mnz-workflow-sli-donut-svg" aria-hidden="true">' . implode('', $parts) . '</svg>';
}

function extractParentChildFromTree(array $children, string $parent_id): array {
	$pairs = [];
	foreach ($children as $c) {
		$cid = $c['serviceid'] ?? '';
		if ($cid !== '' && $parent_id !== '') {
			$pairs[] = [$parent_id, $cid];
			$pairs = array_merge($pairs, extractParentChildFromTree($c['children'] ?? [], $cid));
		}
	}
	return $pairs;
}

function buildDeduplicatedServiceGraph(array $service_trees): array {
	$by_id = [];
	$parent_of = [];

	foreach ($service_trees as $tree) {
		$path = $tree['path_to_root'] ?? [];
		$children_tree = $tree['children_tree'] ?? [];
		$children_flat = flattenServiceChildren($children_tree);

		foreach ($path as $svc) {
			$sid = $svc['serviceid'] ?? '';
			if ($sid === '' || (empty($svc['name']) && empty($sid))) continue;
			if (!isset($by_id[$sid])) $by_id[$sid] = $svc;
		}
		foreach ($children_flat as $svc) {
			$sid = $svc['serviceid'] ?? '';
			if ($sid === '' || (empty($svc['name']) && empty($sid))) continue;
			if (!isset($by_id[$sid])) $by_id[$sid] = $svc;
		}

		for ($i = 0; $i < count($path) - 1; $i++) {
			$parent_id = $path[$i]['serviceid'] ?? '';
			$child_id = $path[$i + 1]['serviceid'] ?? '';
			if ($parent_id !== '' && $child_id !== '') {
				if (!isset($parent_of[$parent_id])) $parent_of[$parent_id] = [];
				if (!in_array($child_id, $parent_of[$parent_id])) $parent_of[$parent_id][] = $child_id;
			}
		}

		$impacted = end($path);
		$impacted_id = $impacted ? ($impacted['serviceid'] ?? '') : '';
		foreach (extractParentChildFromTree($children_tree, $impacted_id) as [$pid, $cid]) {
			if (!isset($parent_of[$pid])) $parent_of[$pid] = [];
			if (!in_array($cid, $parent_of[$pid])) $parent_of[$pid][] = $cid;
		}
	}

	$all_children = [];
	foreach ($parent_of as $kids) {
		foreach ($kids as $cid) $all_children[$cid] = true;
	}
	$roots = [];
	foreach (array_keys($by_id) as $sid) {
		if (!isset($all_children[$sid])) $roots[] = $sid;
	}
	if (empty($roots) && !empty($by_id)) {
		$roots = [array_key_first($by_id)];
	}

	$order = [];
	$seen = [];
	$q = $roots;
	while (!empty($q)) {
		$sid = array_shift($q);
		if (isset($seen[$sid])) continue;
		$seen[$sid] = true;
		$order[] = $sid;
		foreach ($parent_of[$sid] ?? [] as $cid) {
			if (!isset($seen[$cid])) $q[] = $cid;
		}
	}
	foreach (array_keys($by_id) as $sid) {
		if (!isset($seen[$sid])) $order[] = $sid;
	}

	$nodes = [];
	$id_to_idx = [];
	foreach ($order as $idx => $sid) {
		$id_to_idx[$sid] = $idx;
		$nodes[] = ['service_sla', makeServiceNodeCard($by_id[$sid])];
	}

	$edges = [];
	$problem_targets = [];
	foreach ($roots as $rid) {
		if (isset($id_to_idx[$rid])) {
			$problem_targets[] = $id_to_idx[$rid];
		}
	}
	if (empty($problem_targets) && !empty($id_to_idx)) {
		$problem_targets[] = 0;
	}

	foreach ($parent_of as $pid => $kids) {
		$pi = $id_to_idx[$pid] ?? null;
		if ($pi === null) continue;
		foreach ($kids as $cid) {
			$ci = $id_to_idx[$cid] ?? null;
			if ($ci !== null) {
				$edges[] = [$pi, $ci];
			}
		}
	}

	return [$nodes, $edges, $problem_targets];
}

function makeServiceNodeCard(array $svc): CDiv {
	$card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-service-sla');
	$name = $svc['name'] ?? _('Service');
	$card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_INTEGRATIONS)->addClass('mnz-workflow-card-icon'), _('Service')]))->addClass('mnz-workflow-card-header'));
	$card->addItem((new CDiv($name))->addClass('mnz-workflow-card-title'));
	$meta = [];
	if (!empty($svc['has_sla']) && isset($svc['sli'])) {
		$meta[] = _('SLI') . ': ' . (is_numeric($svc['sli']) ? round((float) $svc['sli'], 1) . '%' : $svc['sli']);
	}
	if (!empty($svc['slo']) && is_numeric($svc['slo'])) {
		$meta[] = _('SLO') . ': ' . round((float) $svc['slo'], 1) . '%';
	}
	if (!empty($meta)) {
		$card->addItem((new CDiv(implode(' · ', $meta)))->addClass('mnz-workflow-card-meta'));
	}
	$details = [];
	if (isset($svc['error_budget']) && $svc['error_budget'] !== '' && $svc['error_budget'] !== null) {
		$details[] = _('Error budget') . ': ' . $svc['error_budget'];
	}
	if (isset($svc['downtime']) && $svc['downtime'] !== '' && $svc['downtime'] !== null) {
		$details[] = _('Downtime') . ': ' . $svc['downtime'];
	}
	if (isset($svc['uptime']) && $svc['uptime'] !== '' && $svc['uptime'] !== null) {
		$details[] = _('Uptime') . ': ' . $svc['uptime'];
	}
	if (!empty($details)) {
		$card->addItem((new CDiv(implode(' · ', $details)))->addClass('mnz-workflow-card-detail mnz-workflow-card-sla-detail'));
	}
	return $card;
}

function renderServiceTreeToDiv(array $children, int $depth = 0): CDiv {
	$wrap = (new CDiv())->addClass('mnz-workflow-service-tree-children');
	foreach ($children as $svc) {
		$name = $svc['name'] ?? _('Service');
		$meta = [];
		if (!empty($svc['has_sla']) && isset($svc['sli'])) {
			$meta[] = is_numeric($svc['sli']) ? round((float) $svc['sli'], 1) . '%' : $svc['sli'];
		}
		if (!empty($svc['slo']) && is_numeric($svc['slo'])) {
			$meta[] = _('SLO') . ' ' . round((float) $svc['slo'], 1) . '%';
		}
		$metaStr = !empty($meta) ? ' (' . implode(' · ', $meta) . ')' : '';
		$item = (new CDiv())->addClass('mnz-workflow-service-tree-item')->setAttribute('style', 'padding-left:' . ($depth * 14) . 'px');
		$item->addItem((new CSpan($name))->addClass('mnz-workflow-service-tree-name'));
		if ($metaStr !== '') {
			$item->addItem((new CSpan($metaStr))->addClass('mnz-workflow-service-tree-meta'));
		}
		$wrap->addItem($item);
		if (!empty($svc['children'])) {
			$wrap->addItem(renderServiceTreeToDiv($svc['children'], $depth + 1));
		}
	}
	return $wrap;
}

function makeServicesTreeCard(array $service_trees, string $eventid): CDiv {
	$all_services = [];
	foreach ($service_trees as $tree) {
		$path = $tree['path_to_root'] ?? [];
		$children_tree = $tree['children_tree'] ?? [];
		foreach ($path as $svc) {
			$sid = $svc['serviceid'] ?? '';
			if ($sid !== '' && !isset($all_services[$sid])) {
				$all_services[$sid] = $svc;
			}
		}
		foreach (flattenServiceChildren($children_tree) as $svc) {
			$sid = $svc['serviceid'] ?? '';
			if ($sid !== '' && !isset($all_services[$sid])) {
				$all_services[$sid] = $svc;
			}
		}
	}
	$count = count($all_services);
	$sli_services = collectServicesWithSli($service_trees, 2);

	$card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-services-tree');
	$card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_INTEGRATIONS)->addClass('mnz-workflow-card-icon'), _('Services')]))->addClass('mnz-workflow-card-header'));
	$summary = (new CDiv())->addClass('mnz-workflow-card-body mnz-workflow-card-services-summary');
	$summary->addItem((new CDiv(_s('%1$s service(s)', $count)))->addClass('mnz-workflow-services-count'));
	if (!empty($sli_services)) {
		$donut_wrap = (new CDiv())->addClass('mnz-workflow-services-donuts');
		foreach ($sli_services as $sli_svc) {
			$donut_svg = renderSliDonutSvg($sli_svc['sli'], $sli_svc['slo']);
			$donut_wrap->addItem((new CDiv())->addClass('mnz-workflow-sli-donut-wrap')->addItem(new CWorkflowRawHtml($donut_svg)));
		}
		$summary->addItem($donut_wrap);
	}
	$popover_id = 'mnz-workflow-services-tree-' . $eventid;
	$tree_content = (new CDiv())->addClass('mnz-workflow-services-popover-content');
	$has_any = false;
	if (!empty($sli_services)) {
		$popover_donuts = (new CDiv())->addClass('mnz-workflow-services-popover-donuts');
		foreach ($sli_services as $sli_svc) {
			$donut_svg = renderSliDonutSvg($sli_svc['sli'], $sli_svc['slo']);
			$popover_donuts->addItem((new CDiv())->addClass('mnz-workflow-sli-donut-wrap')->addItem(new CWorkflowRawHtml($donut_svg)));
		}
		$tree_content->addItem($popover_donuts);
	}
	$slo_default = 99.9;
	$all_rows = [];
	$seen_serviceids = [];
	foreach ($service_trees as $tree) {
		$path = $tree['path_to_root'] ?? [];
		$children_tree = $tree['children_tree'] ?? [];
		if (!empty($path) || !empty($children_tree)) {
			$has_any = true;
			$slo_ref = $slo_default;
			foreach (array_merge($path, flattenServiceChildren($children_tree)) as $svc) {
				if (isset($svc['slo']) && is_numeric($svc['slo'])) {
					$slo_ref = (float) $svc['slo'];
					break;
				}
			}
			$addRows = function (array $items, int $level) use (&$addRows, $slo_ref, &$all_rows, &$seen_serviceids): void {
				foreach ($items as $svc) {
					$sid = $svc['serviceid'] ?? '';
					if ($sid !== '' && isset($seen_serviceids[$sid])) {
						if (!empty($svc['children'])) {
							$addRows($svc['children'], $level + 1);
						}
						continue;
					}
					if ($sid !== '') {
						$seen_serviceids[$sid] = true;
					}
					$name = $svc['name'] ?? _('Service');
					$branch = $level > 0 ? '↳ ' : '';
					$sli_val = null;
					if (!empty($svc['has_sla']) && isset($svc['sli']) && is_numeric($svc['sli'])) {
						$sli_val = (float) $svc['sli'];
					}
					$sli_cell = '-';
					if ($sli_val !== null) {
						$cls = 'mnz-sli-badge-sm';
						if ($sli_val >= $slo_ref) {
							$cls .= ' mnz-sli-ok';
						} elseif ($sli_val >= max(0.0, $slo_ref - 5)) {
							$cls .= ' mnz-sli-warn';
						} else {
							$cls .= ' mnz-sli-bad';
						}
						$sli_cell = (new CSpan(round($sli_val, 1) . '%'))->addClass($cls);
					}
					$uptime = isset($svc['uptime']) && $svc['uptime'] !== '' ? $svc['uptime'] : '-';
					$downtime = isset($svc['downtime']) && $svc['downtime'] !== '' ? $svc['downtime'] : '-';
					$err_budget = isset($svc['error_budget']) && $svc['error_budget'] !== '' ? $svc['error_budget'] : '-';
					$col_name = (new CCol($branch . $name))->addClass('mnz-workflow-services-cell-name');
					if ($level > 0) {
						$col_name->setAttribute('style', 'padding-left:' . (12 + $level * 20) . 'px');
					}
					$all_rows[] = [
						$col_name,
						(new CCol($sli_cell))->addClass('mnz-workflow-services-cell-sli'),
						(new CCol($uptime))->addClass('mnz-workflow-services-cell-extra'),
						(new CCol($downtime))->addClass('mnz-workflow-services-cell-extra'),
						(new CCol($err_budget))->addClass('mnz-workflow-services-cell-extra')
					];
					if (!empty($svc['children'])) {
						$addRows($svc['children'], $level + 1);
					}
				}
			};
			foreach ($path as $i => $svc) {
				$addRows([$svc], $i);
			}
			if (!empty($children_tree)) {
				$addRows($children_tree, count($path));
			}
		}
	}
	if (!empty($all_rows)) {
		$table = (new CTable())->addClass('mnz-workflow-services-table list-table');
		$table->setHeader([
			_('Service'),
			_('SLI'),
			_('Uptime'),
			_('Downtime'),
			_('Error budget')
		]);
		foreach ($all_rows as $row) {
			$table->addRow($row);
		}
		$tree_content->addItem($table);
	}
	if (!$has_any) {
		$tree_content->addItem((new CDiv(_('No services')))->addClass('mnz-workflow-card-empty'));
	}
	$icon_span = (new CSpan())
		->addClass(ZBX_ICON_CIRCLE_INFO)
		->addClass('mnz-workflow-services-expand')
		->setAttribute('data-popover-id', $popover_id)
		->setAttribute('data-popover-header', _('Service tree'))
		->setAttribute('title', _('View full service tree'));
	$summary->addItem($icon_span);
	$card->addItem($summary);
	$detail_wrap = (new CDiv($tree_content))->setId($popover_id)->addClass('mnz-workflow-card-detail-hidden');
	$card->addItem($detail_wrap);
	return $card;
}
$view_mode = $data['view_mode'] ?? 'cards';
$severity_filter = $data['severity_filter'] ?? [];
$show_acks = (int) ($data['show_acks'] ?? 1);
$show_resolved = (int) ($data['show_resolved'] ?? 0);
$limit = (int) ($data['limit'] ?? 50);


$html_page = (new CHtmlPage())
	->setTitle(_('Workflow Ops'))
	->setDocUrl('')
	->setControls(
		(new CTag('nav', true,
			(new CList())
				->addItem(
					(new CButton('refresh', _('Refresh')))
						->addClass(ZBX_STYLE_BTN_ALT)
						->setAttribute('onclick', $is_single && !empty($data['eventid']) ? "if(window.mnzWorkflowRefresh){window.mnzWorkflowRefresh();}else{location.reload();} return false;" : "location.reload(); return false;")
				)
				->addItem(
					($is_single
						? (new CLink(_('Back to Problems'), (new CUrl('zabbix.php'))->setArgument('action', 'problem.view')->getUrl()))
						: (new CLink(_('Problems'), (new CUrl('zabbix.php'))->setArgument('action', 'problem.view')->getUrl()))
					)->addClass(ZBX_STYLE_BTN_ALT)
				)
		))->setAttribute('aria-label', _('Content controls'))
	);

$is_refresh = $data['is_refresh'] ?? false;
$content = (new CDiv())->addClass('mnz-workflow-wrapper' . ($is_single ? ' mnz-workflow-single-page' : '') . ($is_popup ? ' mnz-workflow-popup' : ''))->setId('mnz-workflow-content');

require_once dirname(__FILE__).'/../../../include/classes/helpers/CCsrfTokenHelper.php';
$content->addItem((new CScriptTag(
	'window.view=window.view||{};'.
	'window.view.editItem=function(t,d){PopUp("item.edit",d,{dialogueid:"item-edit",dialogue_class:"modal-popup-large",trigger_element:t,prevent_navigation:true})};'.
	'window.view.editHost=function(h,m){var id=m!==undefined?m:h;PopUp("popup.host.edit",{hostid:id},{dialogueid:"host_edit",dialogue_class:"modal-popup-large",prevent_navigation:true})};'.
	'window.view.editTemplate=function(e,t){var tid=t!==undefined?t:e;PopUp("template.edit",{templateid:tid},{dialogueid:"templates-form",dialogue_class:"modal-popup-large",prevent_navigation:true})};'.
	'window.view.editTrigger=function(d){PopUp("trigger.edit",d,{dialogueid:"trigger-edit",dialogue_class:"modal-popup-large",prevent_navigation:true})};'.
	'window.view.executeNow=function(b,d){if(!d||!d.itemids)return;var c=new Curl("zabbix.php");c.setArgument("action","item.execute");d[typeof CSRF_TOKEN_NAME!=="undefined"?CSRF_TOKEN_NAME:"sid"]='.json_encode(CCsrfTokenHelper::get('item')).';var btn=b;function showMsg(good,title,msgs){if(typeof addMessage==="function"&&typeof makeMessageBox==="function"){var inModal=document.getElementById("mnz-workflow-content")&&document.getElementById("mnz-workflow-content").closest(".overlay-dialogue-body");if(inModal){var main=document.getElementById("wrapper")||document.body;var wrap=document.createElement("div");wrap.className="mnz-workflow-exec-toast "+(good?"mnz-workflow-exec-toast-ok":"mnz-workflow-exec-toast-err");wrap.textContent=title||(msgs&&msgs[0])||(good?"OK":"Error");wrap.style.cssText="position:fixed;top:12px;left:50%;transform:translateX(-50%);padding:10px 20px;border-radius:8px;z-index:6999;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,0.3);";main.appendChild(wrap);setTimeout(function(){if(wrap.parentNode)wrap.remove();},4000);}else{addMessage(makeMessageBox(good,msgs||[],title||"",true,!good));}}else{var main=document.getElementById("wrapper")||document.body;var wrap=document.createElement("div");wrap.className="mnz-workflow-exec-toast "+(good?"mnz-workflow-exec-toast-ok":"mnz-workflow-exec-toast-err");wrap.textContent=title||(msgs&&msgs[0])||(good?"OK":"Error");wrap.style.cssText="position:fixed;top:12px;left:50%;transform:translateX(-50%);padding:10px 20px;border-radius:8px;z-index:6999;font-size:13px;box-shadow:0 4px 12px rgba(0,0,0,0.3);";main.appendChild(wrap);setTimeout(function(){if(wrap.parentNode)wrap.remove();},4000);}}if(btn&&btn.classList)btn.classList.add("is-loading");fetch(c.getUrl(),{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify(d)}).then(function(r){return r.json()}).then(function(res){if(res.error){showMsg(false,res.error.title||"",res.error.messages||[]);}else if(res.success){showMsg(true,res.success.title||"Request sent successfully",[]);}}).catch(function(){showMsg(false,"Error",["Unexpected server error."]);}).finally(function(){if(btn&&btn.classList)btn.classList.remove("is-loading");});};'
))->setAttribute('id', 'mnz-workflow-view-init'));

if (!$is_single) {
	$filter_form = (new CForm('get'))
		->setName('mnz-workflow-ops-filter')
		->setAttribute('aria-label', _('Workflow filters'));
	$filter_form->addItem((new CInput('hidden', 'action', 'workflow.ops.view'))->removeId());
	$filter_form->addItem((new CInput('hidden', 'view_mode', $view_mode))->removeId());

	$severity_options = [
		TRIGGER_SEVERITY_NOT_CLASSIFIED => _('Not classified'),
		TRIGGER_SEVERITY_INFORMATION => _('Information'),
		TRIGGER_SEVERITY_WARNING => _('Warning'),
		TRIGGER_SEVERITY_AVERAGE => _('Average'),
		TRIGGER_SEVERITY_HIGH => _('High'),
		TRIGGER_SEVERITY_DISASTER => _('Disaster')
	];
	$severity_checkboxes = [];
	foreach ($severity_options as $sev => $label) {
		$checked = empty($severity_filter) || in_array((string) $sev, $severity_filter, true);
		$cb = (new CCheckBox('severity_filter[]', (string) $sev))
			->setChecked($checked)
			->setLabel($label);
		$severity_checkboxes[] = $cb;
	}

	$filter_row = (new CDiv([
		(new CDiv(_('Severity')))->addClass('mnz-workflow-filter-label'),
		(new CDiv($severity_checkboxes))->addClass('mnz-workflow-filter-severities'),
		(new CDiv([
			(new CInput('hidden', 'show_acks', '0'))->removeId(),
			(new CCheckBox('show_acks', '1'))
				->setChecked($show_acks == 1)
				->setLabel(_('Show acknowledged')),
			(new CInput('hidden', 'show_resolved', '0'))->removeId(),
			(new CCheckBox('show_resolved', '1'))
				->setChecked($show_resolved == 1)
				->setLabel(_('Show resolved')),
			(new CSubmit('apply', _('Apply')))->addClass(ZBX_STYLE_BTN_ALT)
		]))->addClass('mnz-workflow-filter-actions')
	]))->addClass('mnz-workflow-filter-bar');
	$filter_form->addItem($filter_row);
	$content->addItem($filter_form);
}

$canvas = (new CDiv())->addClass('mnz-workflow-canvas' . ($is_single ? ' mnz-workflow-canvas-single mnz-canvas-single' : ''));

if (empty($problems)) {
	$canvas->addItem(
		(new CDiv($is_single
			? _('Problem not found.')
			: _('No problems found. Adjust filters or check Monitoring → Problems.')))
			->addClass('mnz-workflow-empty')
	);
} else {
	if (!$is_single) {
		$canvas->addItem(
			(new CDiv(_s('Showing %1$s of latest problems', count($problems))))
				->addClass('mnz-workflow-header')
		);
	}

	foreach ($problems as $problem) {
		$eventid = $problem['eventid'];
		$triggerid = (int) $problem['objectid'];
		$trigger = $triggers[$triggerid] ?? null;
		$actions_data = $actions_by_event[$eventid] ?? ['actions' => [], 'userids' => [], 'mediatypeids' => []];
		$hostid = $trigger && !empty($trigger['hosts']) ? (int) $trigger['hosts'][0]['hostid'] : 0;
		$host = $hosts[$hostid] ?? null;
		$maintenances = $maintenances_by_event[$eventid] ?? [];
		$templates = $templates_by_host[$hostid] ?? [];
		$problem_tags = $problem['tags'] ?? [];
		$trigger_tags = $trigger['tags'] ?? [];

		$flow = (new CDiv())->addClass('mnz-workflow-flow' . ($is_single ? ' mnz-workflow-flow-single' : ''));
		$flow->setAttribute('data-eventid', $eventid);
		if ($is_single && !empty($data['layout'])) {
			$flow->setAttribute('data-wf-layout', json_encode($data['layout']));
		}

		$severity = (int) ($problem['severity'] ?? 0);
		$severity_name = CSeverityHelper::getName($severity);
		$severity_style = CSeverityHelper::getStyle($severity);
		$clock_str = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem['clock']);
		$host_name = $trigger && !empty($trigger['hosts']) ? ($trigger['hosts'][0]['name'] ?? $trigger['hosts'][0]['host'] ?? '') : '';

		$problem_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'problem.view')
			->setArgument('triggerids', [$triggerid])
			->setArgument('filter_set', '1')
			->getUrl();

		$workflow_single_url = (new CUrl('zabbix.php'))
			->setArgument('action', 'workflow.ops.view')
			->setArgument('eventid', $eventid)
			->getUrl();

		$wf_nodes = [];

		$problem_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-problem');
		$problem_header_content = [(new CSpan())->addClass(ZBX_ICON_ALERTS)->addClass('mnz-workflow-card-icon'), _('Problem')];
		if ($is_single && $triggerid > 0) {
			$analysis_icon = (new CSpan('✨'))
				->addClass('mnz-workflow-analysis-expand')
				->addClass('mnz-workflow-analysis-sparkle')
				->setAttribute('data-eventid', $eventid)
				->setAttribute('title', _('Incident analysis (heatmap)'));
			$problem_header_content[] = (new CSpan($analysis_icon))->addClass('mnz-workflow-analysis-icon-wrap');
		}
		$problem_card->addItem((new CDiv($problem_header_content))->addClass('mnz-workflow-card-header'));
		$problem_clock = (int) ($problem['clock'] ?? 0);
		$r_clock = (int) ($problem['r_clock'] ?? 0);
		$duration_str = null;
		if ($problem_clock > 0) {
			$duration_str = $r_clock > 0
				? zbx_date2age($problem_clock, $r_clock)
				: zbx_date2age($problem_clock, time());
		}
		$header_items = [
			(new CSpan($severity_name))->addClass($severity_style)->addClass('mnz-workflow-card-badge'),
			(new CSpan($clock_str))->addClass('mnz-workflow-card-meta'),
			(new CSpan(_s('Event #%1$s', $eventid)))->addClass('mnz-workflow-card-meta mnz-workflow-card-eventid')->setAttribute('title', _('Event ID'))
		];
		if ($duration_str !== null) {
			$mttr_label = $r_clock > 0 ? _('MTTR') : _('Open for');
			$header_items[] = (new CSpan($mttr_label . ': ' . $duration_str))
				->addClass('mnz-workflow-badge mnz-workflow-badge-mttr')
				->setAttribute('title', $r_clock > 0 ? _('Mean time to resolve') : _('Time since problem opened'));
		}
		$suppression_data = $problem['suppression_data'] ?? [];
		if (!empty($suppression_data) || (isset($problem['suppressed']) && (string) $problem['suppressed'] === (string) ZBX_PROBLEM_SUPPRESSED_TRUE)) {
			$icon = (new CSpan())->addClass(ZBX_ICON_WRENCH_ALT_SMALL)->addClass('mnz-workflow-card-maint-icon');
			$header_items[] = (new CSpan($icon))->addClass('mnz-workflow-card-suppressed')->setAttribute('title', _('Suppressed'));
		}
		$problem_card->addItem((new CDiv($header_items))->addClass('mnz-workflow-card-meta-row'));
		if ($is_single && $problem_count_current !== null && $problem_count_previous !== null) {
			$period_up = $problem_count_current > $problem_count_previous;
			$period_same = $problem_count_current === $problem_count_previous;
			$badge_class = $period_up ? 'mnz-workflow-badge-period-up' : ($period_same ? 'mnz-workflow-badge-period-same' : 'mnz-workflow-badge-period-down');
			$badges_row = (new CDiv())->addClass('mnz-workflow-card-badges-row');
			$badges_row->addItem((new CSpan(_s('30d: %1$s', $problem_count_current)))
				->addClass('mnz-workflow-badge mnz-workflow-badge-period ' . $badge_class)
				->setAttribute('title', _('Incidents in last 30 days')));
			$badges_row->addItem((new CSpan(_s('prev: %1$s', $problem_count_previous)))
				->addClass('mnz-workflow-badge mnz-workflow-badge-period')
				->setAttribute('title', _('Incidents in previous 30 days')));
			$problem_card->addItem($badges_row);
		}
		$problem_name = $problem['name'] ?? _('Unknown');
		$problem_title_row = (new CDiv())->addClass('mnz-workflow-card-title-row');
		$problem_title_row->addItem(
			(new CDiv(
				(new CLink($problem_name, $problem_url))
					->addClass('mnz-workflow-card-link')
			))->addClass('mnz-workflow-card-title')
		);
		if (!$is_single) {
			$problem_title_row->addItem(
				(new CLink(_('Open workflow'), $workflow_single_url))
					->addClass('mnz-workflow-open-link')
					->setAttribute('title', _('View full workflow'))
			);
		}
		$problem_card->addItem($problem_title_row);
		if (mb_strlen($problem_name) > 50) {
			$problem_title_row->setAttribute('title', $problem_name);
		}
		if (!empty($problem['opdata'])) {
			$problem_card->addItem((new CDiv(['Op. data: ', $problem['opdata']]))->addClass('mnz-workflow-card-detail'));
		}
		if ($is_single && !empty($problem_tags)) {
			$tg = makeTagsCompact($problem_tags);
			if ($tg !== null) {
				$problem_card->addItem($tg);
			}
		}
		if ($is_single) {
			$update_link = (new CButton('update', _('Update')))
				->addClass('btn-alt mnz-workflow-problem-update')
				->setAttribute('data-eventid', $eventid)
				->setAttribute('type', 'button')
				->setAttribute('title', _('Acknowledge, add message, change severity, etc.'));
			$problem_card->addItem((new CDiv($update_link))->addClass('mnz-workflow-card-actions-row'));
		} else {
			$problem_card->setAttribute('data-wf-pos', 'problem');
			$flow->addItem($problem_card);
			$flow->addItem(makeWorkflowConnector(_('On host'), 'conn-onhost'));
		}

		$template_card = null;
		$group_card = null;
		if ($is_single) {
			if (!empty($templates)) {
				$template_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-template');
				$template_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_REFERENCE)->addClass('mnz-workflow-card-icon'), _('Template')]))->addClass('mnz-workflow-card-header'));
				$tpl_list = (new CList())->addClass('mnz-workflow-card-list');
				foreach ($templates as $tpl) {
					$tpl_list->addItem((new CListItem($tpl))->addClass('mnz-workflow-card-list-item'));
				}
				$template_card->addItem($tpl_list);
			}
			if ($host && !empty($host['hostgroups'])) {
				$group_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-group');
				$group_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_TREE_TOP_BOTTOM)->addClass('mnz-workflow-card-icon'), _('Host group')]))->addClass('mnz-workflow-card-header'));
				$grp_list = (new CList())->addClass('mnz-workflow-card-list');
				foreach ($host['hostgroups'] as $hg) {
					$grp_list->addItem((new CListItem($hg['name'] ?? ''))->addClass('mnz-workflow-card-list-item'));
				}
				$group_card->addItem($grp_list);
			}
		}

		$host_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-host');
		$host_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_HOME)->addClass('mnz-workflow-card-icon'), _('Host')]))->addClass('mnz-workflow-card-header'));
		if ($host_name !== '') {
			if ($hostid > 0) {
				$host_link = (new CLink($host_name, '#'))
					->addClass('mnz-workflow-card-link')
					->setAttribute('onclick', 'view.editHost(' . $hostid . '); return false;')
					->setAttribute('title', _('Edit host'));
				$host_card->addItem((new CDiv($host_link))->addClass('mnz-workflow-card-title'));
			} else {
				$host_card->addItem((new CDiv($host_name))->addClass('mnz-workflow-card-title'));
			}
			if ($host && isset($host['status']) && (int) $host['status'] === HOST_STATUS_NOT_MONITORED) {
				$host_card->addItem((new CSpan(_('Disabled')))->addClass('mnz-workflow-card-badge status-red'));
			}
			$host_ip = '';
			if ($host && !empty($host['interfaces'])) {
				$main_if = null;
				foreach ($host['interfaces'] as $if) {
					if ((int) ($if['main'] ?? 0) === 1) {
						$main_if = $if;
						break;
					}
				}
				$main_if = $main_if ?? ($host['interfaces'][0] ?? null);
			if ($main_if) {
				$addr = ((int) ($main_if['useip'] ?? 1) === 1)
					? trim($main_if['ip'] ?? '')
					: trim($main_if['dns'] ?? '');
				$port = isset($main_if['port']) && $main_if['port'] !== '' ? (string) $main_if['port'] : null;
				$host_ip = $addr . ($port !== null ? ':' . $port : '');
			}
			}
			if ($host_ip !== '') {
				$host_card->addItem((new CDiv($host_ip))->addClass('mnz-workflow-card-meta'));
			}
			$host_description = trim($host['description'] ?? '');
			if ($is_single && $host_description !== '') {
				$host_card->addItem((new CDiv($host_description))->addClass('mnz-workflow-card-detail'));
			}
			$host_proxyid = (int) ($host['proxyid'] ?? 0);
			if ($host_proxyid > 0) {
				$proxy = $proxy_by_id[$host_proxyid] ?? null;
				$proxy_name = $proxy ? ($proxy['host'] ?? _('Proxy')) : _('Proxy');
				$proxy_line = CWebUser::checkAccess(CRoleHelper::UI_ADMINISTRATION_PROXIES)
					? (new CLink($proxy_name, (new CUrl('zabbix.php'))->setArgument('action', 'proxy.edit')->setArgument('proxyid', $host_proxyid)))->addClass('mnz-workflow-card-link')
					: $proxy_name;
				$host_card->addItem((new CDiv([_('Monitored via').': ', $proxy_line]))->addClass('mnz-workflow-card-meta'));
			}
			if ($hostid > 0 && CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA)) {
				$latest_data_url = (new CUrl('zabbix.php'))
					->setArgument('action', 'latest.view')
					->setArgument('hostids', [$hostid])
					->setArgument('filter_set', '1')
					->getUrl();
				$host_card->addItem((new CDiv((new CLink(_('Latest data'), $latest_data_url))->addClass('mnz-workflow-card-link')))->addClass('mnz-workflow-card-meta'));
			}
			$host_tags = $host && !empty($host['tags']) ? $host['tags'] : [];
			if ($is_single && !empty($host_tags)) {
				$htg = makeTagsCompact($host_tags);
				if ($htg !== null) {
					$host_card->addItem($htg);
				}
			}
			if ($is_single && $host && !empty($host['inventory'])) {
				$inv = $host['inventory'];
				$inv_fields = [
					'type' => _('Type'),
					'os' => _('OS'),
					'hardware' => _('Hardware'),
					'software' => _('Software'),
					'vendor' => _('Vendor'),
					'model' => _('Model'),
					'serialno_a' => _('Serial number'),
					'location' => _('Location'),
					'contact' => _('Contact')
				];
				$inv_items = [];
				foreach ($inv_fields as $key => $label) {
					$val = trim($inv[$key] ?? '');
					if ($val !== '') {
						$inv_items[] = $label . ': ' . $val;
					}
				}
				if (!empty($inv_items)) {
					$host_card->addItem((new CDiv(implode(' · ', $inv_items)))->addClass('mnz-workflow-card-detail'));
				}
			}
		} else {
			$host_card->addItem((new CDiv(_('N/A')))->addClass('mnz-workflow-card-title'));
		}
		if ($is_single) {
			if ($template_card !== null) {
				$wf_nodes[] = ['template', $template_card];
			}
			if ($group_card !== null) {
				$wf_nodes[] = ['group', $group_card];
			}
			$wf_nodes[] = ['host', $host_card];
			if (!empty($maintenances)) {
				$maint_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-maintenance');
				$maint_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_WRENCH_ALT_SMALL)->addClass('mnz-workflow-card-icon'), _('Maintenance')]))->addClass('mnz-workflow-card-header'));
				$maint_list = (new CList())->addClass('mnz-workflow-card-list');
				foreach ($maintenances as $m) {
					$since_str = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $m['active_since'] ?? 0);
					$till_str = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $m['active_till'] ?? 0);
					$desc = isset($m['description']) && $m['description'] !== '' ? ' — ' . $m['description'] : '';
					$maint_list->addItem((new CListItem($m['name'] . ' (' . $since_str . ' — ' . $till_str . ')' . $desc))->addClass('mnz-workflow-card-list-item'));
				}
				$maint_card->addItem($maint_list);
				$wf_nodes[] = ['maintenance', $maint_card];
			}
			$host_maps = $hostid > 0 ? ($maps_by_host[$hostid] ?? []) : [];
			if (!empty($host_maps)) {
				$maps_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-maps');
				$maps_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_MONITORING)->addClass('mnz-workflow-card-icon'), _('Maps')]))->addClass('mnz-workflow-card-header'));
				$maps_list = (new CList())->addClass('mnz-workflow-card-list');
				foreach ($host_maps as $map_info) {
					$map_url = (new CUrl('zabbix.php'))->setArgument('action', 'map.view')->setArgument('sysmapid', $map_info['sysmapid'])->getUrl();
					$map_name = $map_info['name'] ?? _('Map');
					$link = (new CLink($map_name, $map_url))
						->addClass('mnz-workflow-card-link')
						->setAttribute('target', '_blank')
						->setAttribute('rel', 'noopener noreferrer')
						->setAttribute('title', _('Open in new tab'));
					$maps_list->addItem((new CListItem($link))->addClass('mnz-workflow-card-list-item'));
				}
				$maps_card->addItem($maps_list);
				$wf_nodes[] = ['maps', $maps_card];
			}
			$host_dashboards = $host && !empty($host['dashboards']) ? $host['dashboards'] : [];
			if ($hostid > 0) {
				$dashboard_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-dashboard');
				$dashboard_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_DASHBOARDS)->addClass('mnz-workflow-card-icon'), _('Dashboards')]))->addClass('mnz-workflow-card-header'));
				$dash_list = (new CList())->addClass('mnz-workflow-card-list');
				if (!empty($host_dashboards)) {
					foreach ($host_dashboards as $d) {
						$dash_url = (new CUrl('zabbix.php'))->setArgument('action', 'dashboard.view')->setArgument('dashboardid', $d['dashboardid'] ?? '')->getUrl();
						$dash_list->addItem((new CListItem((new CLink($d['name'] ?? _('Dashboard'), $dash_url))->addClass('mnz-workflow-card-link')))->addClass('mnz-workflow-card-list-item'));
					}
				} else {
					$host_dash_url = (new CUrl('zabbix.php'))->setArgument('action', 'host.dashboard.view')->setArgument('hostid', $hostid)->getUrl();
					$dash_list->addItem((new CListItem((new CLink(_('View host dashboard'), $host_dash_url))->addClass('mnz-workflow-card-link')))->addClass('mnz-workflow-card-list-item'));
				}
				$dashboard_card->addItem($dash_list);
				$wf_nodes[] = ['dashboard', $dashboard_card];
			}
		} else {
			$host_card->setAttribute('data-wf-pos', 'host');
			$flow->addItem($host_card);
			$flow->addItem(makeWorkflowConnector(
				($trigger && !empty($trigger['items'])) ? _('Item') : _('Trigger'),
				($trigger && !empty($trigger['items'])) ? 'conn-item' : 'conn-trigger'
			));
		}

		$trigger_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-trigger mnz-workflow-card-trigger-primary');
		$trigger_header_items = [(new CSpan())->addClass(ZBX_ICON_DATA_COLLECTION)->addClass('mnz-workflow-card-icon'), _('Trigger')];
		if ($is_single) {
			$trigger_header_items[] = (new CSpan(_('this event')))->addClass('mnz-workflow-card-badge-small');
		}
		$trigger_card->addItem((new CDiv($trigger_header_items))->addClass('mnz-workflow-card-header'));
		$trigger_desc = $trigger ? ($trigger['description'] ?? '') : _('N/A');
		$workflow_backurl = (new CUrl('zabbix.php'))
			->setArgument('action', 'workflow.ops.view')
			->setArgument('eventid', $data['eventid'] ?? '')
			->setArgument('triggerid', $triggerid)
			->getUrl();
		$trigger_title_content = $trigger && $triggerid
			? (new CLinkAction($trigger_desc))
				->setMenuPopup(CMenuPopupHelper::getTrigger([
					'triggerid' => $triggerid,
					'backurl' => $workflow_backurl,
					'eventid' => $eventid
				]))
			: $trigger_desc;
		$trigger_title_div = (new CDiv($trigger_title_content))->addClass('mnz-workflow-card-title');
		if (mb_strlen($trigger_desc) > 50) {
			$trigger_title_div->setAttribute('title', $trigger_desc);
		}
		$trigger_card->addItem($trigger_title_div);
		if ($trigger && !empty($trigger['expression'])) {
			$expr_escaped = str_replace(["\r\n", "\n", "\r"], ' ', $trigger['expression']);
			$expr_div = (new CDiv())->addClass('mnz-workflow-card-detail mnz-workflow-card-expression');
			$expr_div->addItem((new CSpan(_('Expression').': '))->addClass('mnz-workflow-card-detail-label'));
			$expr_div->addItem((new CSpan($expr_escaped))->addClass('mnz-workflow-card-expression-text')->setAttribute('title', $trigger['expression']));
			$trigger_card->addItem($expr_div);
		}
		$tpl_name = $trigger ? ($trigger_template_by_id[$triggerid] ?? null) : null;
		if ($tpl_name !== null) {
			$trigger_card->addItem((new CDiv([_('Template').': ', $tpl_name]))->addClass('mnz-workflow-card-detail'));
		}
		if ($trigger && !empty($trigger['opdata'])) {
			$trigger_card->addItem((new CDiv(['Op. data: ', $trigger['opdata']]))->addClass('mnz-workflow-card-detail'));
		}
		$trigger_comments = $trigger ? trim($trigger['comments'] ?? '') : '';
		if ($is_single && $trigger_comments !== '') {
			$trigger_card->addItem((new CDiv([_('Description') . ': ', $trigger_comments]))->addClass('mnz-workflow-card-detail'));
		}
		if ($trigger && !empty($trigger['url'])) {
			$trigger_card->addItem((new CDiv([
				_('URL') . ': ',
				(new CLink($trigger['url'], $trigger['url']))
					->addClass('mnz-workflow-card-link')
					->setAttribute('target', '_blank')
					->setAttribute('rel', 'noopener noreferrer')
			]))->addClass('mnz-workflow-card-detail'));
		}
		if ($is_single && $trigger) {
			$trigger_meta = [];
			if (isset($trigger['priority'])) {
				$trigger_meta[] = _('Severity') . ': ' . CSeverityHelper::getName((int) $trigger['priority']);
			}
			if (isset($trigger['manual_close']) && (int) $trigger['manual_close'] === ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED) {
				$trigger_meta[] = _('Manual close') . ': ' . _('Yes');
			}
			if (!empty($trigger_meta)) {
				$trigger_card->addItem((new CDiv(implode(' · ', $trigger_meta)))->addClass('mnz-workflow-card-meta'));
			}
		}
		if (!empty($trigger_tags)) {
			$tg = makeTagsCompact($trigger_tags);
			if ($tg !== null) {
				$trigger_card->addItem($tg);
			}
		}
		if ($is_single && $trigger && !empty($trigger['items'])) {
			$problem_clock = (int) ($problem['clock'] ?? 0);
			$items_ordered = getOrderedItemsFromTrigger($trigger);
			$vals_at_problem = $item_values_at_problem[$eventid] ?? [];
			foreach ($items_ordered as $item) {
				$metrics_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-metrics');
				$metrics_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_I)->addClass('mnz-workflow-card-icon'), _('Item')]))->addClass('mnz-workflow-card-header'));
				if ($problem_clock > 0) {
					$metrics_card->addItem((new CDiv(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem_clock)))->addClass('mnz-workflow-card-meta'));
					$spark_from = $problem_clock - 6 * 3600;
					$spark_from_str = date('Y-m-d H:i:s', $spark_from);
					$spark_to_str = date('Y-m-d H:i:s', $problem_clock);
					$spark_url = (new CUrl('chart.php'))
						->setArgument('from', $spark_from_str)
						->setArgument('to', $spark_to_str)
						->setArgument('type', GRAPH_TYPE_NORMAL)
						->setArgument('width', 220)
						->setArgument('height', 60)
						->setArgument('legend', 0)
						->setArgument('resolve_macros', 1)
						->setArgument('itemids', [$item['itemid']]);
					$spark_url_expanded = (new CUrl('chart.php'))
						->setArgument('from', $spark_from_str)
						->setArgument('to', $spark_to_str)
						->setArgument('type', GRAPH_TYPE_NORMAL)
						->setArgument('width', 800)
						->setArgument('height', 250)
						->setArgument('legend', 1)
						->setArgument('resolve_macros', 1)
						->setArgument('itemids', [$item['itemid']]);
					$spark_wrapper = (new CDiv(
						(new CTag('img', true))
							->setAttribute('src', $spark_url->getUrl())
							->setAttribute('alt', _('Last 6h'))
							->addClass('mnz-workflow-item-spark')
					))
						->addClass('mnz-workflow-item-spark-wrapper')
						->setAttribute('title', _('Click to expand'))
						->setAttribute('data-chart-url-expanded', $spark_url_expanded->getUrl())
						->setAttribute('role', 'button')
						->setAttribute('tabindex', '0');
					$metrics_card->addItem($spark_wrapper);
				}
				$label = (string) ($item['name'] ?? $item['key_'] ?? '');
				$val = $vals_at_problem[$item['itemid']] ?? null;
				$line = $val !== null ? $label . ': ' . $val : $label;
				$link = (new CLinkAction($line))->setMenuPopup(CMenuPopupHelper::getItem([
					'itemid' => $item['itemid'],
					'context' => 'host',
					'backurl' => (new CUrl('zabbix.php'))
						->setArgument('action', 'workflow.ops.view')
						->setArgument('eventid', $data['eventid'] ?? '')
						->getUrl()
				]))->setAttribute('title', $label);
				$item_parts = [$link];
				$detail_bits = buildItemDetailBits($item, $item_template_by_id);
				if (!empty($detail_bits)) {
					$item_parts[] = (new CDiv(implode(' · ', $detail_bits)))->addClass('mnz-workflow-item-detail');
				}
				$item_tags = $item_tags_by_id[$item['itemid']] ?? [];
				if (!empty($item_tags)) {
					$tg_div = makeTagsCompact($item_tags);
					if ($tg_div !== null) {
						$item_parts[] = $tg_div;
					}
				}
				if (in_array((int) ($item['type'] ?? 0), checkNowAllowedTypes(), true)) {
					$exec_btn = (new CButton('exec_now', _('Execute now')))
						->addClass('btn-alt mnz-workflow-item-exec-now')
						->setAttribute('data-itemid', $item['itemid'])
						->setAttribute('type', 'button')
						->setAttribute('title', _('Execute now'));
					$item_parts[] = (new CDiv($exec_btn))->addClass('mnz-workflow-item-exec-row');
				}
				$li = (new CListItem($item_parts))->addClass('mnz-workflow-card-list-item');
				$metrics_card->addItem((new CList())->addClass('mnz-workflow-card-list')->addItem($li));
				$wf_nodes[] = ['metrics', $metrics_card];
			}
		} elseif (!$is_single && $trigger && !empty($trigger['items'])) {
			$problem_clock = (int) ($problem['clock'] ?? 0);
			$list_metrics_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-metrics');
			$list_metrics_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_I)->addClass('mnz-workflow-card-icon'), _('Item')]))->addClass('mnz-workflow-card-header'));
			if ($problem_clock > 0) {
				$list_metrics_card->addItem((new CDiv(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $problem_clock)))->addClass('mnz-workflow-card-meta'));
			}
			$vals_at_problem = $item_values_at_problem[$eventid] ?? [];
			$items_list = (new CList())->addClass('mnz-workflow-card-list');
			$items_ordered = getOrderedItemsFromTrigger($trigger);
			foreach ($items_ordered as $item) {
				$label = (string) ($item['name'] ?? $item['key_'] ?? '');
				$val = $vals_at_problem[$item['itemid']] ?? null;
				$line = $val !== null ? $label . ': ' . $val : $label;
				$link = (new CLinkAction($line))->setMenuPopup(CMenuPopupHelper::getItem([
					'itemid' => $item['itemid'],
					'context' => 'host',
					'backurl' => (new CUrl('zabbix.php'))
						->setArgument('action', 'workflow.ops.view')
						->getUrl()
				]))->setAttribute('title', $label);
				$item_parts = [$link];
				$detail_bits = buildItemDetailBits($item, $item_template_by_id);
				if (!empty($detail_bits)) {
					$item_parts[] = (new CDiv(implode(' · ', $detail_bits)))->addClass('mnz-workflow-item-detail');
				}
				$item_tags = $item_tags_by_id[$item['itemid']] ?? [];
				if (!empty($item_tags)) {
					$tg_div = makeTagsCompact($item_tags);
					if ($tg_div !== null) {
						$item_parts[] = $tg_div;
					}
				}
				$item_triggers = $triggers_by_item[$item['itemid']] ?? [];
				if (!empty($item_triggers)) {
					$triggers_div = (new CDiv())->addClass('mnz-workflow-item-triggers');
					$triggers_div->addItem((new CSpan(_('Triggers').': '))->addClass('mnz-workflow-card-detail-label'));
					$first = true;
					foreach ($item_triggers as $tinfo) {
						if (!$first) {
							$triggers_div->addItem(new CSpan(', '));
						}
						$first = false;
						$t_url = (new CUrl('zabbix.php'))->setArgument('action', 'trigger.edit')->setArgument('triggerid', $tinfo['triggerid'])->setArgument('context', 'host')->getUrl();
						$label = $tinfo['description'];
						if ((int) $tinfo['triggerid'] === $triggerid) {
							$label = $label . ' (' . _('this event') . ')';
						}
						$triggers_div->addItem((new CLink($label, $t_url))->addClass('mnz-workflow-card-link'));
					}
					$item_parts[] = $triggers_div;
				}
				$li = (new CListItem($item_parts))->addClass('mnz-workflow-card-list-item');
				$items_list->addItem($li);
			}
			$list_metrics_card->addItem($items_list);
			$list_metrics_card->setAttribute('data-wf-pos', 'item');
			$flow->addItem($list_metrics_card);
			$flow->addItem(makeWorkflowConnector(_('Trigger'), 'conn-trigger'));
		}

		if ($is_single) {
			$display_trigger_ids = [];
			if ($trigger && !empty($trigger['items'])) {
				foreach ($trigger['items'] as $item) {
					$item_triggers = $triggers_by_item[$item['itemid']] ?? [];
					foreach ($item_triggers as $tinfo) {
						$tid = (int) $tinfo['triggerid'];
						if (!in_array($tid, $display_trigger_ids, true)) {
							$display_trigger_ids[] = $tid;
						}
					}
				}
			}
			if (!in_array($triggerid, $display_trigger_ids, true)) {
				array_unshift($display_trigger_ids, $triggerid);
			}
			usort($display_trigger_ids, function ($a, $b) use ($triggerid) {
				if ($a === $triggerid) return -1;
				if ($b === $triggerid) return 1;
				return $a <=> $b;
			});
			foreach ($display_trigger_ids as $tid) {
				if ($tid === $triggerid) {
					$wf_nodes[] = ['trigger', $trigger_card];
				} else {
					$t = $triggers[$tid] ?? null;
					if (!$t) continue;
					$t_card = makeTriggerNodeCard($t, $trigger_template_by_id, $workflow_backurl ?? '', $eventid);
					$wf_nodes[] = ['trigger', $t_card];
				}
			}
			$dependencies = $trigger && !empty($trigger['dependencies']) ? $trigger['dependencies'] : [];
			if (!empty($dependencies)) {
				$deps_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-dependencies');
				$deps_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_REFERENCE)->addClass('mnz-workflow-card-icon'), _('Depends on')]))->addClass('mnz-workflow-card-header'));
				$deps_list = (new CList())->addClass('mnz-workflow-card-list');
				foreach ($dependencies as $dep) {
					$dep_triggerid = (int) ($dep['triggerid'] ?? 0);
					$dep_desc = $dep['description'] ?? _('Trigger') . ' #' . $dep_triggerid;
					$dep_url = (new CUrl('zabbix.php'))->setArgument('action', 'trigger.edit')->setArgument('triggerid', $dep_triggerid)->setArgument('context', 'host')->getUrl();
					$deps_list->addItem((new CListItem((new CLink($dep_desc, $dep_url))->addClass('mnz-workflow-card-link')))->addClass('mnz-workflow-card-list-item'));
				}
				$deps_card->addItem($deps_list);
				$wf_nodes[] = ['dependencies', $deps_card];
			}
		} else {
			$trigger_card->setAttribute('data-wf-pos', 'trigger');
			$flow->addItem($trigger_card);
		}

		if ($is_single) {
			$wf_nodes[] = ['problem', $problem_card];

			if (!empty($service_trees)) {
				$services_card = makeServicesTreeCard($service_trees, (string) $eventid);
				$wf_nodes[] = ['service_sla', $services_card];
			}

			$acknowledges = $problem['acknowledges'] ?? [];
			if (!empty($acknowledges) && $show_acks) {
				foreach (getAckNodesByType($acknowledges, $users) as [$type, $card]) {
					$wf_nodes[] = [$type, $card];
				}
			}
		} else {
			$flow->addItem(makeWorkflowDropConnector());
		}

		$actions_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-actions');
		$actions_header = (new CDiv([(new CSpan())->addClass(ZBX_ICON_BELL)->addClass('mnz-workflow-card-icon'), _('Actions')]))->addClass('mnz-workflow-card-header');
		$actions_card->addItem($actions_header);
		if (!empty($actions_data['actions'])) {
			$count = count($actions_data['actions']);
			$summary_text = _s('%1$s action(s)', $count);
			$popover_id = 'mnz-workflow-actions-detail-' . $eventid;
			$actions_body = (new CDiv())->addClass('mnz-workflow-card-body mnz-workflow-card-actions-summary');
			$actions_body->addItem((new CDiv($summary_text))->addClass('mnz-workflow-actions-count'));
			$icon_span = (new CSpan())
				->addClass(ZBX_ICON_CIRCLE_INFO)
				->addClass('mnz-workflow-actions-expand')
				->setAttribute('data-popover-id', $popover_id)
				->setAttribute('title', _('View actions'));
			$actions_body->addItem($icon_span);
			$actions_card->addItem($actions_body);
			$detail_wrap = (new CDiv(
				(new CDiv(makeEventDetailsActionsTable($actions_data, $users, $mediatypes)))
					->addClass('mnz-workflow-actions-popover-table')
			))->setId($popover_id)->addClass('mnz-workflow-actions-detail-hidden');
			$actions_card->addItem($detail_wrap);
		} else {
			$actions_card->addItem((new CDiv(_('No actions for this event')))->addClass('mnz-workflow-card-body mnz-workflow-card-empty'));
		}
		$user_ids = array_keys($actions_data['userids'] ?? []);
		if ($is_single) {
			$wf_nodes[] = ['actions', $actions_card];
		} else {
			$actions_card->setAttribute('data-wf-pos', 'actions');
			$flow->addItem($actions_card);
			$flow->addItem(makeWorkflowConnector(_('Media types'), 'conn-media'));
		}

		$media_type_ids = array_keys($actions_data['mediatypeids'] ?? []);
		$media_types_used = [];
		foreach ($media_type_ids as $mtid) {
			if (isset($mediatypes[$mtid])) {
				$mt = $mediatypes[$mtid];
				$type_label = getMediaTypeTypeLabel((int) ($mt['type'] ?? 0));
				$media_types_used[] = [
					'name' => $mt['name'] ?? $type_label,
					'type' => $type_label,
					'maxattempts' => $mt['maxattempts'] ?? ''
				];
			}
		}
		$media_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-mediatypes');
		$media_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_ENVELOPE_FILLED)->addClass('mnz-workflow-card-icon'), _('Media types')]))->addClass('mnz-workflow-card-header'));
		if (!empty($media_types_used)) {
			$mt_list = (new CList())->addClass('mnz-workflow-card-list');
			foreach ($media_types_used as $mt) {
				$detail = $mt['name'];
				if ($mt['maxattempts'] !== '' && $mt['maxattempts'] > 0) {
					$detail .= _s(' (max %1$s attempts)', $mt['maxattempts']);
				}
				$mt_list->addItem((new CListItem($detail))->addClass('mnz-workflow-card-list-item'));
			}
			$media_card->addItem($mt_list);
		} else {
			$media_card->addItem((new CDiv(_('No media types used for alerts')))->addClass('mnz-workflow-card-body mnz-workflow-card-empty'));
		}
		if ($is_single) {
			$wf_nodes[] = ['media', $media_card];
		} else {
			$media_card->setAttribute('data-wf-pos', 'media');
			$flow->addItem($media_card);
		}

		if (!empty($user_ids)) {
			$users_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-users');
			$users_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_ADMINISTRATION)->addClass('mnz-workflow-card-icon'), _('Recipients')]))->addClass('mnz-workflow-card-header'));
			$users_list = (new CList())->addClass('mnz-workflow-card-list');
			foreach ($user_ids as $uid) {
				$u = $users[$uid] ?? null;
				$label = $u ? (trim(($u['name'] ?? '') . ' ' . ($u['surname'] ?? '')) ?: $u['username']) : _s('User #%1$s', $uid);
				$users_list->addItem((new CListItem($label))->addClass('mnz-workflow-card-list-item'));
			}
			$users_card->addItem($users_list);
			if ($is_single) {
				$wf_nodes[] = ['recipients', $users_card];
			} else {
				$flow->addItem(makeWorkflowConnector(_('Recipients'), 'conn-recipients'));
				$users_card->setAttribute('data-wf-pos', 'recipients');
				$flow->addItem($users_card);
			}
		}

		$r_eventid = (int) ($problem['r_eventid'] ?? 0);
		$r_clock = (int) ($problem['r_clock'] ?? 0);
		if ($is_single && $r_eventid > 0) {
			$vals_at_resolution = $item_values_at_resolution[$eventid] ?? [];
			if ($trigger && !empty($trigger['items'])) {
				$items_ordered = getOrderedItemsFromTrigger($trigger);
				$has_vals = !empty($vals_at_resolution);
				if ($has_vals || !empty($items_ordered)) {
					$metrics_resolved_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-metrics-resolved');
					$metrics_resolved_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_I)->addClass('mnz-workflow-card-icon'), _('Item') . ' (' . _('value at resolution') . ')']))->addClass('mnz-workflow-card-header'));
					if ($r_clock > 0) {
						$metrics_resolved_card->addItem((new CDiv(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $r_clock)))->addClass('mnz-workflow-card-meta'));
					}
					$res_items_list = (new CList())->addClass('mnz-workflow-card-list');
					foreach ($items_ordered as $item) {
						$label = (string) ($item['name'] ?? $item['key_'] ?? '');
						$val = $vals_at_resolution[$item['itemid']] ?? null;
						$line = $val !== null ? $label . ': ' . $val : ($label !== '' ? $label : _('N/A'));
						$link = (new CLinkAction($line))->setMenuPopup(CMenuPopupHelper::getItem([
							'itemid' => $item['itemid'],
							'context' => 'host',
							'backurl' => (new CUrl('zabbix.php'))
								->setArgument('action', 'workflow.ops.view')
								->setArgument('eventid', $data['eventid'] ?? '')
								->getUrl()
						]))->setAttribute('title', $label);
						$item_parts = [$link];
						$detail_bits = buildItemDetailBits($item, $item_template_by_id);
						if (!empty($detail_bits)) {
							$item_parts[] = (new CDiv(implode(' · ', $detail_bits)))->addClass('mnz-workflow-item-detail');
						}
						$item_tags = $item_tags_by_id[$item['itemid']] ?? [];
						if (!empty($item_tags)) {
							$tg_div = makeTagsCompact($item_tags);
							if ($tg_div !== null) {
								$item_parts[] = $tg_div;
							}
						}
						$li = (new CListItem($item_parts))->addClass('mnz-workflow-card-list-item');
						$res_items_list->addItem($li);
					}
					$metrics_resolved_card->addItem($res_items_list);
					$wf_nodes[] = ['metrics_resolved', $metrics_resolved_card];
				}
			}
			$resolved_card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-resolved');
			$resolved_card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_CHECK)->addClass('mnz-workflow-card-icon'), _('Resolved')]))->addClass('mnz-workflow-card-header'));
			$resolved_card->addItem((new CDiv(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $r_clock)))->addClass('mnz-workflow-card-title'));
			$problem_clock = (int) ($problem['clock'] ?? 0);
			if ($problem_clock > 0 && $r_clock > 0) {
				$duration_str = zbx_date2age($problem_clock, $r_clock);
				$resolved_card->addItem((new CDiv(_s('MTTR: %1$s', $duration_str)))->addClass('mnz-workflow-card-meta mnz-workflow-card-mttr'));
			}
			$wf_nodes[] = ['resolved', $resolved_card];
		}

		if ($is_single) {
			$phase1_types = ['template', 'group', 'host', 'maintenance', 'maps', 'dashboard', 'metrics', 'trigger', 'dependencies'];
			$phase2_types = ['problem', 'service_sla', 'ack_close', 'ack_acknowledge', 'ack_message', 'ack_severity', 'ack_unacknowledge', 'ack_suppress', 'ack_unsuppress', 'ack_rank_cause', 'ack_rank_symptom', 'actions', 'media', 'recipients', 'metrics_resolved', 'resolved'];
			$problem_branch_types = ['ack_close', 'ack_acknowledge', 'ack_message', 'ack_severity', 'ack_unacknowledge', 'ack_suppress', 'ack_unsuppress', 'ack_rank_cause', 'ack_rank_symptom'];
			$problem_chain_types = ['problem', 'actions', 'media', 'recipients', 'metrics_resolved', 'resolved'];

			$idx = 0;
			$template_idx = null;
			$group_idx = null;
			$host_idx = null;
			$maintenance_idx = null;
			$maps_idx = null;
			$dashboard_idx = null;
			$metrics_indices = [];
			$trigger_start_idx = null;
			$trigger_count = 0;
			$dependencies_idx = null;
			$problem_idx = null;
			$service_sla_idx = null;
			$node_types = [];

			$phase1 = (new CDiv())->addClass('mnz-workflow-phase-row mnz-workflow-phase-row-1');
			$phase2 = (new CDiv())->addClass('mnz-workflow-phase-row mnz-workflow-phase-row-2');

			foreach ($wf_nodes as [$type, $card]) {
				$node_types[$idx] = $type;
				$node = makeWorkflowNode($type, $card);
				if (in_array($type, $phase1_types, true)) {
					if ($type === 'template') {
						$template_idx = $idx;
					} elseif ($type === 'group') {
						$group_idx = $idx;
					} elseif ($type === 'host') {
						$host_idx = $idx;
					} elseif ($type === 'maintenance') {
						$maintenance_idx = $idx;
					} elseif ($type === 'maps') {
						$maps_idx = $idx;
					} elseif ($type === 'dashboard') {
						$dashboard_idx = $idx;
					} elseif ($type === 'metrics') {
						$metrics_indices[] = $idx;
					} elseif ($type === 'trigger') {
						if ($trigger_start_idx === null) {
							$trigger_start_idx = $idx;
						}
						$trigger_count++;
					} elseif ($type === 'dependencies') {
						$dependencies_idx = $idx;
					}
					$phase1->addItem($node);
				} elseif (in_array($type, $phase2_types, true)) {
					if ($type === 'problem') {
						$problem_idx = $idx;
					} elseif ($type === 'service_sla') {
						$service_sla_idx = $idx;
					}
					$phase2->addItem($node);
				}
				$idx++;
			}

			$edges_simple = [];
			$problem_path_edge_indices = [];
			$has_metrics = !empty($metrics_indices);
			$has_triggers = $trigger_count > 0 && $trigger_start_idx !== null && $problem_idx !== null;
			$has_host = $host_idx !== null;
			if ($has_host && $has_metrics && $has_triggers) {
				if ($template_idx !== null) {
					$edges_simple[] = [$template_idx, $host_idx];
					$problem_path_edge_indices[] = count($edges_simple) - 1;
				}
				if ($group_idx !== null) {
					$edges_simple[] = [$group_idx, $host_idx];
					$problem_path_edge_indices[] = count($edges_simple) - 1;
				}
				if ($maintenance_idx !== null) {
					$edges_simple[] = [$host_idx, $maintenance_idx];
					$problem_path_edge_indices[] = count($edges_simple) - 1;
				}
				if ($maps_idx !== null) {
					$edges_simple[] = [$host_idx, $maps_idx];
					$problem_path_edge_indices[] = count($edges_simple) - 1;
				}
				if ($dashboard_idx !== null) {
					$edges_simple[] = [$host_idx, $dashboard_idx];
					$problem_path_edge_indices[] = count($edges_simple) - 1;
				}
				if ($service_sla_idx !== null && $problem_idx !== null) {
					$edges_simple[] = [$problem_idx, $service_sla_idx];
					$problem_path_edge_indices[] = count($edges_simple) - 1;
				}
				foreach ($metrics_indices as $mi) {
					$edges_simple[] = [$host_idx, $mi];
					$problem_path_edge_indices[] = count($edges_simple) - 1;
				}
				$ei = count($edges_simple);
				foreach ($metrics_indices as $mi) {
					for ($t = $trigger_start_idx; $t < $trigger_start_idx + $trigger_count; $t++) {
						$edges_simple[] = [$mi, $t];
						$problem_path_edge_indices[] = count($edges_simple) - 1;
						$ei++;
					}
				}
				$edges_simple[] = [$trigger_start_idx, $problem_idx];
				$problem_path_edge_indices[] = $ei++;
				if ($dependencies_idx !== null) {
					$edges_simple[] = [$dependencies_idx, $trigger_start_idx];
					$problem_path_edge_indices[] = count($edges_simple) - 1;
				}
				$prev_chain = $problem_idx;
				for ($p = $problem_idx + 1; $p < $idx; $p++) {
					$pt = $node_types[$p] ?? '';
					if (in_array($pt, $problem_branch_types, true)) {
						$edges_simple[] = [$problem_idx, $p];
						$problem_path_edge_indices[] = count($edges_simple) - 1;
					} elseif (in_array($pt, $problem_chain_types, true)) {
						$edges_simple[] = [$prev_chain, $p];
						$problem_path_edge_indices[] = count($edges_simple) - 1;
						$prev_chain = $p;
					}
				}
			} else {
				for ($i = 0; $i < $idx - 1; $i++) {
					$edges_simple[] = [$i, $i + 1];
					$problem_path_edge_indices[] = $i;
				}
			}
			$flow->setAttribute('data-wf-edges', json_encode($edges_simple));
			$flow->setAttribute('data-wf-problem-edges', json_encode($problem_path_edge_indices));

			$svg = (new CTag('svg', true))
				->addClass('mnz-workflow-svg')
				->setAttribute('id', 'mnz-workflow-svg')
				->setAttribute('aria-hidden', 'true')
				->setAttribute('overflow', 'visible');
			$flow->addItem($svg);
			$flow->addItem($phase1);
			$flow->addItem($phase2);
		}

		if ($is_single) {
			$minimap = (new CDiv())->addClass('mnz-workflow-minimap')
				->setAttribute('title', _('Click to navigate') . '. ' . _('Ctrl + scroll to zoom'));
			$zoom_toolbar = (new CDiv())->addClass('mnz-workflow-zoom-toolbar');
			$zoom_toolbar->addItem((new CButton('zoom_out', '−'))
				->addClass('mnz-workflow-zoom-btn')
				->setAttribute('data-zoom', 'out')
				->setAttribute('title', _('Zoom out') . ' (Ctrl+scroll)')
				->setAttribute('type', 'button'));
			$zoom_toolbar->addItem((new CButton('zoom_in', '+'))
				->addClass('mnz-workflow-zoom-btn')
				->setAttribute('data-zoom', 'in')
				->setAttribute('title', _('Zoom in') . ' (Ctrl+scroll)')
				->setAttribute('type', 'button'));
			$zoom_toolbar->addItem((new CButton('export_pdf', 'PDF'))
				->addClass('mnz-workflow-zoom-btn mnz-workflow-export-pdf')
				->setAttribute('title', _('Export workflow to PDF'))
				->setAttribute('type', 'button'));
			$zoom_wrapper = (new CDiv())->addClass('mnz-workflow-zoom-wrapper');
			$zoom_wrapper->addItem($flow);
			$canvas_scroll = (new CDiv())->addClass('mnz-workflow-canvas-scroll');
			$canvas_scroll->addItem($zoom_wrapper);
			$canvas->addItem($minimap);
			$canvas->addItem($zoom_toolbar);
			$canvas->addItem($canvas_scroll);
		} else {
			$canvas->addItem($flow);
		}
	}
}

function getAckActionLabel(int $action): string {
	$labels = [
		ZBX_PROBLEM_UPDATE_CLOSE => _('Close problem'),
		ZBX_PROBLEM_UPDATE_ACKNOWLEDGE => _('Acknowledge'),
		ZBX_PROBLEM_UPDATE_MESSAGE => _('Message'),
		ZBX_PROBLEM_UPDATE_SEVERITY => _('Change severity'),
		ZBX_PROBLEM_UPDATE_UNACKNOWLEDGE => _('Unacknowledge'),
		ZBX_PROBLEM_UPDATE_SUPPRESS => _('Suppress'),
		ZBX_PROBLEM_UPDATE_UNSUPPRESS => _('Unsuppress'),
		ZBX_PROBLEM_UPDATE_RANK_TO_CAUSE => _('Convert to cause'),
		ZBX_PROBLEM_UPDATE_RANK_TO_SYMPTOM => _('Convert to symptom')
	];
	return $labels[$action] ?? _('Update');
}

function getAckNodesByType(array $acknowledges, array $users): array {
	$action_types = [
		ZBX_PROBLEM_UPDATE_CLOSE => 'ack_close',
		ZBX_PROBLEM_UPDATE_ACKNOWLEDGE => 'ack_acknowledge',
		ZBX_PROBLEM_UPDATE_MESSAGE => 'ack_message',
		ZBX_PROBLEM_UPDATE_SEVERITY => 'ack_severity',
		ZBX_PROBLEM_UPDATE_UNACKNOWLEDGE => 'ack_unacknowledge',
		ZBX_PROBLEM_UPDATE_SUPPRESS => 'ack_suppress',
		ZBX_PROBLEM_UPDATE_UNSUPPRESS => 'ack_unsuppress',
		ZBX_PROBLEM_UPDATE_RANK_TO_CAUSE => 'ack_rank_cause',
		ZBX_PROBLEM_UPDATE_RANK_TO_SYMPTOM => 'ack_rank_symptom'
	];
	$type_order = array_values($action_types);
	$enriched = [];
	foreach ($acknowledges as $ack) {
		$action = (int) ($ack['action'] ?? 0);
		$user_label = isset($users[$ack['userid']])
			? (trim(($users[$ack['userid']]['name'] ?? '') . ' ' . ($users[$ack['userid']]['surname'] ?? '')) ?: $users[$ack['userid']]['username'])
			: _s('User #%1$s', $ack['userid']);
		$clock_str = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $ack['clock'] ?? 0);
		$primary_type = null;
		$primary_bit = null;
		foreach ($action_types as $bit => $type) {
			if (($action & $bit) === $bit) {
				if ($primary_type === null) {
					$primary_type = $type;
					$primary_bit = $bit;
				}
			}
		}
		$enriched[] = array_merge($ack, [
			'_user_label' => $user_label,
			'_clock_str' => $clock_str,
			'_primary_type' => $primary_type ?? 'ack_message',
			'_primary_bit' => $primary_bit ?? ZBX_PROBLEM_UPDATE_MESSAGE
		]);
	}
	usort($enriched, function ($a, $b) {
		return ($a['clock'] ?? 0) <=> ($b['clock'] ?? 0);
	});
	$nodes = [];
	$item = end($enriched);
	if ($item !== false) {
		$type = $item['_primary_type'];
		$bit = $item['_primary_bit'];
		$label = getAckActionLabel($bit);
		$card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-ack');
		$card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_COMMAND)->addClass('mnz-workflow-card-icon'), $label]))->addClass('mnz-workflow-card-header'));
		$card->addItem((new CDiv($item['_user_label'] . ' — ' . $item['_clock_str']))->addClass('mnz-workflow-card-meta'));
		if ($bit === ZBX_PROBLEM_UPDATE_MESSAGE && !empty($item['message'])) {
			$card->addItem((new CDiv($item['message']))->addClass('mnz-workflow-card-detail mnz-workflow-card-ack-message'));
		} elseif ($bit === ZBX_PROBLEM_UPDATE_SEVERITY && isset($item['old_severity'], $item['new_severity'])) {
			$card->addItem((new CDiv(CSeverityHelper::getName((int) $item['old_severity']) . ' → ' . CSeverityHelper::getName((int) $item['new_severity'])))->addClass('mnz-workflow-card-detail'));
		} elseif ($bit === ZBX_PROBLEM_UPDATE_SUPPRESS && !empty($item['suppress_until'])) {
			$card->addItem((new CDiv(_('Until') . ' ' . zbx_date2str(DATE_TIME_FORMAT_SECONDS, $item['suppress_until'])))->addClass('mnz-workflow-card-detail'));
		}
		$nodes[] = [$type, $card];
	}
	return $nodes;
}

function makeWorkflowNodeBody($icon_class, $title, $meta = null): CDiv {
	$body = (new CDiv());
	$header = (new CDiv())->addClass('mnz-workflow-node-header');
	$header->addItem((new CSpan())->addClass('mnz-workflow-node-icon')->addClass($icon_class));
	$header->addItem((new CSpan($title))->addClass('mnz-workflow-node-title'));
	$body->addItem($header);
	if ($meta !== null && $meta !== '') {
		$body->addItem((new CDiv($meta))->addClass('mnz-workflow-node-meta'));
	}
	return $body;
}

function makeTriggerNodeCard(array $t, array $trigger_template_by_id, string $workflow_backurl, string $eventid): CDiv {
	$tid = (int) ($t['triggerid'] ?? 0);
	$card = (new CDiv())->addClass('mnz-workflow-card mnz-workflow-card-trigger');
	$card->addItem((new CDiv([(new CSpan())->addClass(ZBX_ICON_DATA_COLLECTION)->addClass('mnz-workflow-card-icon'), _('Trigger')]))->addClass('mnz-workflow-card-header'));
	$desc = $t['description'] ?? _('N/A');
	$title_content = $tid
		? (new CLinkAction($desc))
			->setMenuPopup(CMenuPopupHelper::getTrigger([
				'triggerid' => $tid,
				'backurl' => $workflow_backurl,
				'eventid' => $eventid
			]))
		: $desc;
	$title_div = (new CDiv($title_content))->addClass('mnz-workflow-card-title');
	if (mb_strlen($desc) > 50) {
		$title_div->setAttribute('title', $desc);
	}
	$card->addItem($title_div);
	if (!empty($t['expression'])) {
		$expr_escaped = str_replace(["\r\n", "\n", "\r"], ' ', $t['expression']);
		$expr_div = (new CDiv())->addClass('mnz-workflow-card-detail mnz-workflow-card-expression');
		$expr_div->addItem((new CSpan(_('Expression').': '))->addClass('mnz-workflow-card-detail-label'));
		$expr_div->addItem((new CSpan($expr_escaped))->addClass('mnz-workflow-card-expression-text')->setAttribute('title', $t['expression']));
		$card->addItem($expr_div);
	}
	$tpl_name = $trigger_template_by_id[$tid] ?? null;
	if ($tpl_name !== null) {
		$card->addItem((new CDiv([_('Template').': ', $tpl_name]))->addClass('mnz-workflow-card-detail'));
	}
	if (!empty($t['opdata'])) {
		$card->addItem((new CDiv(['Op. data: ', $t['opdata']]))->addClass('mnz-workflow-card-detail'));
	}
	$trigger_tags = $t['tags'] ?? [];
	if (!empty($trigger_tags)) {
		$tg = makeTagsCompact($trigger_tags);
		if ($tg !== null) {
			$card->addItem($tg);
		}
	}
	return $card;
}

function makeWorkflowNode(string $type, CDiv $body): CDiv {
	$connector = (new CDiv())->addClass('mnz-workflow-node-connector')->setAttribute('data-wf-connector', 'center');
	$connector->addItem((new CSpan())->addClass('mnz-workflow-connector-dot'));
	$node = (new CDiv())
		->addClass('mnz-workflow-node mnz-workflow-node-' . $type)
		->setAttribute('data-wf-node', $type);
	$body->addClass('mnz-workflow-node-body');
	$node->addItem($body);
	$node->addItem($connector);
	return $node;
}

function makeWorkflowEdge(string $label = '', bool $vertical = false): CDiv {
	$c = (new CDiv())->addClass('mnz-workflow-edge' . ($vertical ? ' mnz-workflow-edge-vertical' : ''));
	if ($label !== '') {
		$c->addItem((new CDiv($label))->addClass('mnz-workflow-edge-label'));
	}
	$c->addItem((new CDiv())->addClass('mnz-workflow-edge-line'));
	return $c;
}

function makeWorkflowConnector(string $label, ?string $pos = null): CDiv {
	$c = (new CDiv())->addClass('mnz-workflow-connector');
	if ($pos !== null) {
		$c->setAttribute('data-wf-pos', $pos);
	}
	$c->addItem((new CDiv($label))->addClass('mnz-workflow-connector-label'));
	$c->addItem((new CDiv())->addClass('mnz-workflow-connector-line'));
	return $c;
}

function makeWorkflowDropConnector(): CDiv {
	$c = (new CDiv())->addClass('mnz-workflow-drop-connector')->setAttribute('data-wf-pos', 'drop');
	$c->addItem((new CDiv(_('Notify')))->addClass('mnz-workflow-drop-label'));
	$c->addItem((new CDiv())->addClass('mnz-workflow-drop-line'));
	return $c;
}

$content->addItem($canvas);

if (!$is_refresh) {
	$wf_refresh_url = '';
	if ($is_single && !empty($data['eventid'])) {
		$wf_refresh_curl = new CUrl('zabbix.php');
		$wf_refresh_curl->setArgument('action', 'workflow.ops.view.refresh');
		$wf_refresh_curl->setArgument('eventid', $data['eventid']);
		$wf_refresh_curl->setArgument('show_acks', (string) $show_acks);
		$wf_refresh_curl->setArgument('show_resolved', (string) $show_resolved);
		$wf_refresh_url = $wf_refresh_curl->getUrl();
	}
	if ($is_single) {
		$content->addItem((new CTag('script', true))->setAttribute('src', 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js'));
		$content->addItem((new CTag('script', true))->setAttribute('src', 'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js'));
	}
	ob_start();
	$wf_layout_save_url = (new CUrl('zabbix.php'))->setArgument('action', 'workflow.ops.layout.save')->getUrl();
	$wf_analysis_url = (new CUrl('zabbix.php'))->setArgument('action', 'workflow.ops.incident.analysis')->getUrl();
	include dirname(__FILE__).'/js/workflow.ops.view.js.php';
	$content->addItem(new CScriptTag(ob_get_clean()));
}

if (!$is_popup) {
	$content->addItem(
		(new CDiv(_('Developed by MonZphere')))
			->addClass('mnz-module-footer mnz-workflow-modal-footer mnz-workflow-footer-centered')
	);
}

function getMediaTypeTypeLabel(int $type): string {
	$labels = [
		MEDIA_TYPE_EMAIL => _('Email'),
		MEDIA_TYPE_EXEC => _('Script'),
		MEDIA_TYPE_SMS => _('SMS'),
		MEDIA_TYPE_WEBHOOK => _('Webhook')
	];
	return $labels[$type] ?? _('Other');
}

if ($is_popup) {
	$developed_by = _('Developed by MonZphere');
	$footer_js = '(function(){setTimeout(function(){var o=document.querySelector(".mnz-workflow-ops-modal")||document.querySelector("[data-dialogueid=\\"mnz-workflow-ops-popup\\"]");if(o){var f=o.querySelector(".overlay-dialogue-footer");if(f&&!f.querySelector(".mnz-workflow-modal-footer")){var d=document.createElement("div");d.className="mnz-workflow-modal-footer mnz-workflow-footer-centered";d.textContent=' . json_encode($developed_by) . ';d.style.cssText="font-size:11px;color:#6c757d;text-align:center;width:100%;";f.insertBefore(d,f.firstChild);}}},50);})();';
	echo json_encode([
		'header' => _('Workflow Ops'),
		'body' => $content->toString(),
		'buttons' => [
			['title' => _('Close'), 'class' => 'btn-alt js-cancel', 'cancel' => true, 'action' => '']
		],
		'script_inline' => $footer_js
	]);
} elseif ($is_refresh) {
	echo json_encode(['body' => $content->toString()]);
} else {
	$html_page->addItem($content);
	$html_page->show();
}
