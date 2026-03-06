<?php declare(strict_types = 0);

namespace Modules\WorkflowOps\Actions;

use API;
use CController;
use CControllerResponseData;
use CProfile;
use CRoleHelper;
use CWebUser;

class CControllerWorkflowOpsView extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventid' => 'id',
			'triggerid' => 'id',
			'view_mode' => 'in cards,timeline',
			'show_acks' => 'in 0,1',
			'show_resolved' => 'in 0,1',
			'limit' => 'ge 1|le 500'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return CWebUser::checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		$eventid = $this->getInput('eventid', '');
		$is_single = $eventid !== '';
		$is_apply = $this->hasInput('apply');
		$view_mode = $this->getInput('view_mode', CProfile::get('mnz.workflow.ops.view_mode', 'cards'));
		$severity_filter = $is_apply
			? (array) $this->getInput('severity_filter', [])
			: $this->getInput('severity_filter', CProfile::getArray('mnz.workflow.ops.severity_filter', []));
		$show_acks = (int) $this->getInput('show_acks', CProfile::get('mnz.workflow.ops.show_acks', '1'));
		$show_resolved = (int) $this->getInput('show_resolved', CProfile::get('mnz.workflow.ops.show_resolved', '0'));
		$limit = (int) $this->getInput('limit', CProfile::get('mnz.workflow.ops.limit', '50'));

		if ($this->hasInput('view_mode')) {
			CProfile::update('mnz.workflow.ops.view_mode', $view_mode, PROFILE_TYPE_STR);
		}
		if ($is_apply) {
			CProfile::updateArray('mnz.workflow.ops.severity_filter', $severity_filter, PROFILE_TYPE_STR);
		}
		if ($this->hasInput('show_acks')) {
			CProfile::update('mnz.workflow.ops.show_acks', (string) $show_acks, PROFILE_TYPE_STR);
		}
		if ($this->hasInput('show_resolved')) {
			CProfile::update('mnz.workflow.ops.show_resolved', (string) $show_resolved, PROFILE_TYPE_STR);
		}
		if ($this->hasInput('limit')) {
			CProfile::update('mnz.workflow.ops.limit', (string) $limit, PROFILE_TYPE_STR);
		}

		$data = [
			'eventid' => $eventid,
			'is_single' => $is_single,
			'view_mode' => $view_mode,
			'severity_filter' => $severity_filter,
			'show_acks' => $show_acks,
			'show_resolved' => $show_resolved,
			'limit' => $limit,
			'problems' => [],
			'triggers' => [],
			'actions_by_event' => [],
			'users' => [],
			'mediatypes' => [],
			'hosts' => [],
			'maintenances_by_event' => [],
			'templates_by_host' => [],
			'item_values_at_problem' => [],
			'item_values_at_resolution' => [],
			'item_template_by_id' => [],
			'trigger_template_by_id' => [],
			'item_tags_by_id' => [],
			'triggers_by_item' => [],
			'problem_count_current' => null,
			'problem_count_previous' => null,
			'layout' => null,
			'maps_by_host' => []
		];

		if ($is_single) {
			$layout_json = CProfile::get('mnz.workflow.ops.layout', '');
			if ($layout_json !== '') {
				$layout_data = json_decode($layout_json, true);
				if (is_array($layout_data)) {
					$data['layout'] = $layout_data;
				}
			}
		}

		if ($is_single) {
			$this->fetchSingleProblemWorkflow($data, $eventid);
		} else {
			$this->fetchProblemsList($data, $severity_filter, $show_acks, $show_resolved, $limit);
		}

		$response = new CControllerResponseData($data);
		$response->setTitle(_('Workflow Ops'));
		$this->setResponse($response);
	}

	protected function fetchSingleProblemWorkflow(array &$data, string $eventid): void {
		$problem_params = [
			'output' => ['eventid', 'objectid', 'clock', 'ns', 'r_eventid', 'r_clock', 'r_ns', 'name', 'severity', 'acknowledged', 'opdata', 'suppressed'],
			'selectAcknowledges' => ['acknowledgeid', 'userid', 'clock', 'message', 'action', 'old_severity', 'new_severity', 'suppress_until', 'taskid'],
			'selectTags' => ['tag', 'value'],
			'selectSuppressionData' => ['maintenanceid', 'suppress_until', 'userid'],
			'eventids' => [$eventid],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'recent' => true
		];
		$problems = API::Problem()->get($problem_params);
		if (empty($problems)) {
			$events = API::Event()->get([
				'output' => ['eventid', 'objectid', 'clock', 'ns', 'r_eventid', 'name', 'severity', 'acknowledged', 'opdata', 'suppressed'],
				'selectAcknowledges' => ['acknowledgeid', 'userid', 'clock', 'message', 'action', 'old_severity', 'new_severity', 'suppress_until', 'taskid'],
				'selectTags' => ['tag', 'value'],
				'selectSuppressionData' => ['maintenanceid', 'suppress_until', 'userid'],
				'eventids' => [$eventid],
				'source' => EVENT_SOURCE_TRIGGERS,
				'object' => EVENT_OBJECT_TRIGGER,
				'value' => TRIGGER_VALUE_TRUE,
				'nopermissions' => true
			]);
			if (empty($events)) {
				return;
			}
			$ev = $events[0];
			$r_eventid = (int) ($ev['r_eventid'] ?? 0);
			$r_clock = 0;
			$r_ns = 0;
			if ($r_eventid > 0) {
				$recovery_events = API::Event()->get([
					'output' => ['clock', 'ns'],
					'eventids' => [$r_eventid],
					'nopermissions' => true
				]);
				if (!empty($recovery_events)) {
					$r_clock = (int) ($recovery_events[0]['clock'] ?? 0);
					$r_ns = (int) ($recovery_events[0]['ns'] ?? 0);
				}
			}
			$problem = [
				'eventid' => $ev['eventid'],
				'objectid' => $ev['objectid'],
				'clock' => $ev['clock'],
				'ns' => $ev['ns'] ?? 0,
				'r_eventid' => $r_eventid,
				'r_clock' => $r_clock,
				'r_ns' => $r_ns,
				'name' => $ev['name'] ?? '',
				'severity' => $ev['severity'] ?? 0,
				'acknowledged' => $ev['acknowledged'] ?? 0,
				'opdata' => $ev['opdata'] ?? '',
				'suppressed' => $ev['suppressed'] ?? 0,
				'acknowledges' => $ev['acknowledges'] ?? [],
				'tags' => $ev['tags'] ?? [],
				'suppression_data' => $ev['suppression_data'] ?? []
			];
			$problems = [$problem];
		} else {
			$problem = $problems[0];
		}
		$data['problems'] = [$problem];
		$triggerid = (int) $problem['objectid'];
		$data['triggers'] = [];
		$data['hosts'] = [];
		$data['proxy_by_id'] = [];
		$data['maintenances_by_event'] = [];
		$data['templates_by_host'] = [];

		$triggers_raw = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'priority', 'expression', 'comments', 'opdata', 'url', 'manual_close', 'templateid'],
			'triggerids' => [$triggerid],
			'selectHosts' => ['hostid', 'host', 'name', 'status'],
			'selectItems' => ['itemid', 'name', 'key_', 'hostid', 'value_type', 'units', 'delay', 'type', 'templateid'],
			'selectFunctions' => ['itemid', 'function', 'parameter'],
			'selectTags' => ['tag', 'value'],
			'selectDependencies' => ['triggerid', 'description'],
			'expandExpression' => true
		]);
		if (!empty($triggers_raw)) {
			$trigger = $triggers_raw[0];
			$data['triggers'][$triggerid] = $trigger;
			$this->buildItemTemplateNames($data);
			$this->buildTriggerTemplateNames($data);
			$this->buildItemTagsAndTriggers($data);
			$hostid = !empty($trigger['hosts']) ? (int) $trigger['hosts'][0]['hostid'] : 0;
			if ($hostid > 0) {
				$hosts_raw = API::Host()->get([
					'output' => ['hostid', 'host', 'name', 'description', 'status', 'monitored_by', 'proxyid', 'inventory_mode'],
					'hostids' => [$hostid],
					'selectHostGroups' => ['groupid', 'name'],
					'selectParentTemplates' => ['templateid', 'host', 'name'],
					'selectTags' => ['tag', 'value'],
					'selectInterfaces' => ['ip', 'dns', 'port', 'main', 'useip'],
					'selectDashboards' => ['hostid', 'dashboardid', 'name'],
					'selectInventory' => ['os', 'hardware', 'software', 'contact', 'location', 'serialno_a', 'model', 'vendor', 'type']
				]);
				if (!empty($hosts_raw)) {
					$data['hosts'][$hostid] = $hosts_raw[0];
					$templates = $hosts_raw[0]['parentTemplates'] ?? [];
					foreach ($templates as $tpl) {
						$data['templates_by_host'][$hostid][] = $tpl['name'];
					}
					$proxyid = (int) ($hosts_raw[0]['proxyid'] ?? 0);
					if ($proxyid > 0 && CWebUser::checkAccess(CRoleHelper::UI_ADMINISTRATION_PROXIES)) {
						$proxies = API::Proxy()->get(['output' => ['proxyid', 'host'], 'proxyids' => [$proxyid]]);
						if (!empty($proxies)) {
							$data['proxy_by_id'][$proxyid] = $proxies[0];
						}
					}
				}
				if (CWebUser::checkAccess(CRoleHelper::UI_CONFIGURATION_MAINTENANCE)) {
					$host = $data['hosts'][$hostid] ?? null;
					$groupids = $host ? array_column($host['hostgroups'] ?? [], 'groupid') : [];
					$maint_base = [
						'output' => ['maintenanceid', 'name', 'description', 'active_since', 'active_till'],
						'preservekeys' => true
					];
					$all_maint = [];
					try {
						$by_host = API::Maintenance()->get(array_merge($maint_base, ['hostids' => [$hostid]]));
						$all_maint = $by_host;
						if (!empty($groupids)) {
							$by_group = API::Maintenance()->get(array_merge($maint_base, ['groupids' => $groupids]));
							$all_maint = $all_maint + $by_group;
						}
					} catch (\Throwable $e) {
					}
					$problem_clock = (int) ($problem['clock'] ?? 0);
					$window_from = $problem_clock - 86400;
					$window_to = $problem_clock + 86400;
					$relevant = [];
					foreach ($all_maint as $m) {
						$since = (int) ($m['active_since'] ?? 0);
						$till = (int) ($m['active_till'] ?? 0);
						if ($till >= $window_from && $since <= $window_to) {
							$relevant[] = $m;
						}
					}
					$data['maintenances_by_event'][$eventid] = $relevant;
				}
				if (empty($data['maintenances_by_event'][$eventid])) {
					$suppression_data = $problem['suppression_data'] ?? [];
					$maint_ids = [];
					foreach ($suppression_data as $sd) {
						$mid = (int) ($sd['maintenanceid'] ?? 0);
						if ($mid > 0) {
							$maint_ids[$mid] = true;
						}
					}
					if (!empty($maint_ids)) {
						try {
							$by_suppress = API::Maintenance()->get([
								'output' => ['maintenanceid', 'name', 'description', 'active_since', 'active_till'],
								'maintenanceids' => array_keys($maint_ids),
								'preservekeys' => true
							]);
							if (!empty($by_suppress)) {
								$data['maintenances_by_event'][$eventid] = array_values($by_suppress);
							}
						} catch (\Throwable $e) {
						}
					}
				}
				if (CWebUser::checkAccess(CRoleHelper::UI_MONITORING_MAPS)) {
					try {
						$all_maps = API::Map()->get([
							'output' => ['sysmapid', 'name'],
							'selectSelements' => ['elementtype', 'elements'],
							'limit' => 100
						]);
						$data['maps_by_host'][$hostid] = [];
						foreach ($all_maps as $m) {
							foreach ($m['selements'] ?? [] as $sel) {
								if ((int) ($sel['elementtype'] ?? -1) === SYSMAP_ELEMENT_TYPE_HOST
										&& !empty($sel['elements'][0]['hostid'])
										&& (int) $sel['elements'][0]['hostid'] === $hostid) {
									$data['maps_by_host'][$hostid][] = ['sysmapid' => $m['sysmapid'], 'name' => $m['name']];
									break;
								}
							}
						}
					} catch (\Throwable $e) {
					}
				}
				if (!isset($data['maps_by_host'][$hostid])) {
					$data['maps_by_host'][$hostid] = [];
				}
			}
		}

		$data['item_values_at_problem'] = [];
		$data['item_values_at_resolution'] = [];
		if (!empty($data['triggers'][$triggerid]['items'])) {
			require_once dirname(__FILE__).'/../../../include/items.inc.php';
			$problem_clock = (int) ($problem['clock'] ?? 0);
			$problem_ns = (int) ($problem['ns'] ?? 0);
			$r_clock = (int) ($problem['r_clock'] ?? 0);
			$r_ns = (int) ($problem['r_ns'] ?? 0);
			$r_eventid = (int) ($problem['r_eventid'] ?? 0);
			foreach ($data['triggers'][$triggerid]['items'] as $item) {
				$item_for_history = ['itemid' => $item['itemid'], 'value_type' => (int) ($item['value_type'] ?? 0)];
				$history_at_problem = \Manager::History()->getValueAt($item_for_history, $problem_clock, $problem_ns);
				if (is_array($history_at_problem) && array_key_exists('value', $history_at_problem)
						&& ($item['value_type'] ?? 0) != ITEM_VALUE_TYPE_BINARY) {
					$item_full = $item + ['units' => $item['units'] ?? '', 'valuemap' => $item['valuemap'] ?? []];
					$data['item_values_at_problem'][$eventid][$item['itemid']] = formatHistoryValue(
						$history_at_problem['value'],
						$item_full
					);
				} else {
					$data['item_values_at_problem'][$eventid][$item['itemid']] = null;
				}
				if ($r_eventid > 0 && $r_clock > 0) {
					$history_at_resolution = \Manager::History()->getValueAt($item_for_history, $r_clock, $r_ns);
					if (is_array($history_at_resolution) && array_key_exists('value', $history_at_resolution)
							&& ($item['value_type'] ?? 0) != ITEM_VALUE_TYPE_BINARY) {
						$item_full = $item + ['units' => $item['units'] ?? '', 'valuemap' => $item['valuemap'] ?? []];
						$data['item_values_at_resolution'][$eventid][$item['itemid']] = formatHistoryValue(
							$history_at_resolution['value'],
							$item_full
						);
					} else {
						$data['item_values_at_resolution'][$eventid][$item['itemid']] = null;
					}
				}
			}
		}

		require_once dirname(__FILE__).'/../../../include/actions.inc.php';
		$event = array_merge($problem, ['object' => EVENT_OBJECT_TRIGGER, 'source' => EVENT_SOURCE_TRIGGERS]);
		$actions_raw = getEventDetailsActions($event);
		$data['actions_by_event'][$eventid] = $actions_raw;
		$userids = array_keys($actions_raw['userids'] ?? []);
		$mediatypeids = array_keys($actions_raw['mediatypeids'] ?? []);
		if (!empty($userids)) {
			$data['users'] = API::User()->get([
				'output' => ['userid', 'username', 'name', 'surname'],
				'userids' => $userids,
				'preservekeys' => true
			]);
		}
		if (!empty($mediatypeids)) {
			$data['mediatypes'] = API::Mediatype()->get([
				'output' => ['mediatypeid', 'name', 'type', 'maxattempts', 'status', 'description'],
				'mediatypeids' => $mediatypeids,
				'preservekeys' => true
			]);
		}

		$hostid = !empty($data['triggers'][$triggerid]['hosts']) ? (int) $data['triggers'][$triggerid]['hosts'][0]['hostid'] : 0;
		$data['service_trees'] = [];
		if ($hostid > 0) {
			$data['service_trees'] = \Modules\WorkflowOps\Actions\CControllerWorkflowOpsServiceImpact::getServiceTreesForHost((string) $hostid);
		}

		$data['problem_count_current'] = null;
		$data['problem_count_previous'] = null;
		$problem_clock = (int) ($problem['clock'] ?? 0);
		if ($problem_clock > 0) {
			$period_days = 30;
			$period_sec = $period_days * 86400;
			$now = time();
			$ref_time = min($problem_clock, $now);
			$curr_from = $ref_time - $period_sec;
			$prev_from = $ref_time - 2 * $period_sec;
			try {
				$curr_count = API::Event()->get([
					'objectids' => [$triggerid],
					'source' => EVENT_SOURCE_TRIGGERS,
					'object' => EVENT_OBJECT_TRIGGER,
					'value' => TRIGGER_VALUE_TRUE,
					'time_from' => $curr_from,
					'time_till' => $ref_time,
					'countOutput' => true,
					'nopermissions' => true
				]);
				$prev_count = API::Event()->get([
					'objectids' => [$triggerid],
					'source' => EVENT_SOURCE_TRIGGERS,
					'object' => EVENT_OBJECT_TRIGGER,
					'value' => TRIGGER_VALUE_TRUE,
					'time_from' => $prev_from,
					'time_till' => $curr_from,
					'countOutput' => true,
					'nopermissions' => true
				]);
				$data['problem_count_current'] = is_int($curr_count) ? $curr_count : (int) $curr_count;
				$data['problem_count_previous'] = is_int($prev_count) ? $prev_count : (int) $prev_count;
			} catch (\Throwable $e) {
			}
		}
	}

	private function fetchProblemsList(array &$data, array $severity_filter, int $show_acks, int $show_resolved, int $limit): void {
		$problem_params = [
			'output' => ['eventid', 'objectid', 'clock', 'ns', 'r_eventid', 'r_clock', 'name', 'severity', 'acknowledged'],
			'selectAcknowledges' => ['acknowledgeid', 'userid', 'clock', 'message', 'action', 'old_severity', 'new_severity', 'suppress_until', 'taskid'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'sortfield' => ['eventid'],
			'sortorder' => [ZBX_SORT_DOWN],
			'limit' => $limit
		];
		if (!empty($severity_filter)) {
			$problem_params['severities'] = array_map('intval', $severity_filter);
		}
		if ($show_acks === 0) {
			$problem_params['acknowledged'] = false;
		}
		if ($show_resolved === 1) {
			$problem_params['recent'] = true;
			$problem_params['time_from'] = time() - 30 * 86400;
		}
		$problems = API::Problem()->get($problem_params);
		if (empty($problems)) {
			return;
		}

		$triggerids = array_unique(array_column($problems, 'objectid'));
		$triggers_raw = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'priority', 'expression', 'opdata', 'templateid'],
			'triggerids' => $triggerids,
			'selectHosts' => ['hostid', 'host', 'name', 'status'],
			'selectTags' => ['tag', 'value'],
			'selectItems' => ['itemid', 'name', 'key_', 'hostid', 'value_type', 'units', 'delay', 'type', 'templateid'],
			'selectFunctions' => ['itemid', 'function', 'parameter'],
			'expandExpression' => true
		]);
		$data['triggers'] = [];
		foreach ($triggers_raw as $t) {
			$data['triggers'][$t['triggerid']] = $t;
		}
		$this->buildItemTemplateNames($data);
		$this->buildItemTagsAndTriggers($data);
		$this->buildTriggerTemplateNames($data);

		$hostids = [];
		foreach ($data['triggers'] as $t) {
			if (!empty($t['hosts'])) {
				$hid = (int) $t['hosts'][0]['hostid'];
				if ($hid > 0) {
					$hostids[$hid] = true;
				}
			}
		}
		if (!empty($hostids)) {
			$hostids = array_keys($hostids);
			$hosts_raw = API::Host()->get([
				'output' => ['hostid', 'host', 'name', 'description', 'status'],
				'hostids' => $hostids,
				'selectHostGroups' => ['groupid', 'name'],
				'selectParentTemplates' => ['templateid', 'host', 'name'],
				'selectTags' => ['tag', 'value'],
				'selectInterfaces' => ['ip', 'dns', 'port', 'main', 'useip'],
				'selectDashboards' => ['hostid', 'dashboardid', 'name']
			]);
			foreach ($hosts_raw as $h) {
				$data['hosts'][$h['hostid']] = $h;
				$templates = $h['parentTemplates'] ?? [];
				foreach ($templates as $tpl) {
					$data['templates_by_host'][$h['hostid']][] = $tpl['name'];
				}
			}
		}

		$data['item_values_at_problem'] = [];
		if (!empty($data['triggers'])) {
			require_once dirname(__FILE__).'/../../../include/items.inc.php';
			foreach ($problems as $problem) {
				$eventid = $problem['eventid'];
				$triggerid = (int) $problem['objectid'];
				$trigger = $data['triggers'][$triggerid] ?? null;
				if (!$trigger || empty($trigger['items'])) {
					continue;
				}
				$problem_clock = (int) ($problem['clock'] ?? 0);
				$problem_ns = (int) ($problem['ns'] ?? 0);
				foreach ($trigger['items'] as $item) {
					$item_for_history = ['itemid' => $item['itemid'], 'value_type' => (int) ($item['value_type'] ?? 0)];
					$history = \Manager::History()->getValueAt($item_for_history, $problem_clock, $problem_ns);
					if (is_array($history) && array_key_exists('value', $history)
							&& (($item['value_type'] ?? 0) != ITEM_VALUE_TYPE_BINARY)) {
						$item_full = $item + ['units' => $item['units'] ?? '', 'valuemap' => $item['valuemap'] ?? []];
						$data['item_values_at_problem'][$eventid][$item['itemid']] = formatHistoryValue(
							$history['value'],
							$item_full
						);
					} else {
						$data['item_values_at_problem'][$eventid][$item['itemid']] = null;
					}
				}
			}
		}

		require_once dirname(__FILE__).'/../../../include/actions.inc.php';
		$actions_by_event = [];
		$userids = [];
		$mediatypeids = [];
		foreach ($problems as $ev) {
			$event = array_merge($ev, ['object' => EVENT_OBJECT_TRIGGER, 'source' => EVENT_SOURCE_TRIGGERS]);
			$actions_raw = getEventDetailsActions($event);
			$actions_by_event[$ev['eventid']] = $actions_raw;
			if (!empty($actions_raw['userids'])) {
				$userids = array_merge($userids, array_keys($actions_raw['userids']));
			}
			if (!empty($actions_raw['mediatypeids'])) {
				$mediatypeids = array_merge($mediatypeids, array_keys($actions_raw['mediatypeids']));
			}
		}
		$userids = array_unique($userids);
		$mediatypeids = array_unique($mediatypeids);
		$data['problems'] = $problems;
		$data['actions_by_event'] = $actions_by_event;
		if (!empty($userids)) {
			$data['users'] = API::User()->get([
				'output' => ['userid', 'username', 'name', 'surname'],
				'userids' => $userids,
				'preservekeys' => true
			]);
		}
		if (!empty($mediatypeids)) {
			$data['mediatypes'] = API::Mediatype()->get([
				'output' => ['mediatypeid', 'name', 'type', 'maxattempts'],
				'mediatypeids' => $mediatypeids,
				'preservekeys' => true
			]);
		}
	}

	private function buildItemTemplateNames(array &$data): void {
		require_once dirname(__FILE__).'/../../../include/defines.inc.php';
		require_once dirname(__FILE__).'/../../../include/items.inc.php';
		$hosts_by_id = [];
		foreach ($data['triggers'] ?? [] as $trigger) {
			foreach ($trigger['hosts'] ?? [] as $h) {
				$hosts_by_id[$h['hostid']] = $h;
			}
		}
		foreach ($data['hosts'] ?? [] as $hostid => $h) {
			$hosts_by_id[$hostid] = $h;
		}
		$items_with_template = [];
		$data['item_template_by_id'] = $data['item_template_by_id'] ?? [];
		foreach ($data['triggers'] ?? [] as $trigger) {
			foreach ($trigger['items'] ?? [] as $item) {
				$host = $hosts_by_id[$item['hostid'] ?? 0] ?? null;
				if ($host && (int) ($host['status'] ?? 0) === HOST_STATUS_TEMPLATE) {
					$data['item_template_by_id'][$item['itemid']] = $host['name'] ?? $host['host'] ?? '';
				} elseif ((int) ($item['templateid'] ?? 0) > 0) {
					$items_with_template[$item['itemid']] = $item;
				}
			}
		}
		if (!empty($items_with_template)) {
			$parent_templates = getItemParentTemplates(array_values($items_with_template), ZBX_FLAG_DISCOVERY_NORMAL);
			foreach (array_keys($items_with_template) as $itemid) {
				$current = $itemid;
				$template_name = null;
				while (isset($parent_templates['links'][$current])) {
					$info = $parent_templates['links'][$current];
					$hostid = (int) ($info['hostid'] ?? 0);
					if ($hostid > 0 && isset($parent_templates['templates'][$hostid])) {
						$template_name = $parent_templates['templates'][$hostid]['name'] ?? null;
					}
					$current = $info['itemid'];
				}
				if ($template_name !== null) {
					$data['item_template_by_id'][$itemid] = $template_name;
				}
			}
		}
	}

	private function buildTriggerTemplateNames(array &$data): void {
		require_once dirname(__FILE__).'/../../../include/defines.inc.php';
		require_once dirname(__FILE__).'/../../../include/triggers.inc.php';
		$hosts_by_id = [];
		foreach ($data['triggers'] ?? [] as $trigger) {
			foreach ($trigger['hosts'] ?? [] as $h) {
				$hosts_by_id[$h['hostid']] = $h;
			}
		}
		foreach ($data['hosts'] ?? [] as $hostid => $h) {
			$hosts_by_id[$hostid] = $h;
		}
		$triggers_with_template = [];
		$data['trigger_template_by_id'] = $data['trigger_template_by_id'] ?? [];
		foreach ($data['triggers'] ?? [] as $trigger) {
			$host = null;
			if (!empty($trigger['hosts'])) {
				$host = $hosts_by_id[$trigger['hosts'][0]['hostid']] ?? null;
			}
			if ($host && (int) ($host['status'] ?? 0) === HOST_STATUS_TEMPLATE) {
				$data['trigger_template_by_id'][$trigger['triggerid']] = $host['name'] ?? $host['host'] ?? '';
			} elseif ((int) ($trigger['templateid'] ?? 0) > 0) {
				$triggers_with_template[$trigger['triggerid']] = $trigger;
			}
		}
		if (!empty($triggers_with_template)) {
			$parent_templates = getTriggerParentTemplates(array_values($triggers_with_template), ZBX_FLAG_DISCOVERY_NORMAL);
			foreach (array_keys($triggers_with_template) as $triggerid) {
				$current = $triggerid;
				$template_name = null;
				while (isset($parent_templates['links'][$current])) {
					$info = $parent_templates['links'][$current];
					$hostids = $info['hostids'] ?? [0];
					$hostid = is_array($hostids) ? (int) ($hostids[0] ?? 0) : (int) $hostids;
					if ($hostid > 0 && isset($parent_templates['templates'][$hostid])) {
						$template_name = $parent_templates['templates'][$hostid]['name'] ?? null;
					}
					$current = $info['triggerid'];
				}
				if ($template_name !== null) {
					$data['trigger_template_by_id'][$triggerid] = $template_name;
				}
			}
		}
	}

	private function buildItemTagsAndTriggers(array &$data): void {
		$itemids = [];
		foreach ($data['triggers'] ?? [] as $trigger) {
			foreach ($trigger['items'] ?? [] as $item) {
				$itemids[$item['itemid']] = true;
			}
		}
		$itemids = array_keys($itemids);
		if (empty($itemids)) {
			return;
		}
		$items_with_tags = API::Item()->get([
			'output' => ['itemid'],
			'itemids' => $itemids,
			'selectTags' => ['tag', 'value'],
			'nopermissions' => true,
			'preservekeys' => true
		]);
		$data['item_tags_by_id'] = [];
		foreach ($items_with_tags as $item) {
			$data['item_tags_by_id'][$item['itemid']] = $item['tags'] ?? [];
		}
		$all_triggers = API::Trigger()->get([
			'output' => ['triggerid', 'description', 'expression', 'opdata', 'templateid'],
			'itemids' => $itemids,
			'selectHosts' => ['hostid', 'host', 'name', 'status'],
			'selectFunctions' => ['itemid'],
			'selectTags' => ['tag', 'value'],
			'expandExpression' => true,
			'nopermissions' => true,
			'preservekeys' => true
		]);
		foreach ($all_triggers as $t) {
			$data['triggers'][$t['triggerid']] = array_merge($data['triggers'][$t['triggerid']] ?? [], $t);
		}
		$data['triggers_by_item'] = [];
		foreach ($all_triggers as $t) {
			$host_name = !empty($t['hosts']) ? ($t['hosts'][0]['name'] ?? $t['hosts'][0]['host'] ?? '') : '';
			$desc = $t['description'] ?? '';
			foreach ($t['functions'] ?? [] as $fn) {
				$iid = $fn['itemid'];
				if (!isset($data['triggers_by_item'][$iid])) {
					$data['triggers_by_item'][$iid] = [];
				}
				$data['triggers_by_item'][$iid][$t['triggerid']] = [
					'triggerid' => $t['triggerid'],
					'description' => $desc,
					'host_name' => $host_name
				];
			}
		}
	}
}
