<?php declare(strict_types = 0);

namespace Modules\WorkflowOps\Actions;

use API;
use CController;
use CRoleHelper;
use CWebUser;

class CControllerWorkflowOpsIncidentAnalysis extends CController {

	protected function init(): void {
		$this->disableCsrfValidation();
	}

	protected function checkInput(): bool {
		$fields = [
			'eventid' => 'required|id'
		];
		return $this->validateInput($fields);
	}

	protected function checkPermissions(): bool {
		return $this->checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
	}

	protected function doAction(): void {
		header('Content-Type: application/json; charset=UTF-8');

		$eventid = $this->getInput('eventid');
		$event = null;
		$triggerid = 0;

		$events = API::Event()->get([
			'output' => ['eventid', 'objectid', 'clock'],
			'eventids' => $eventid
		]);
		if (!empty($events)) {
			$event = $events[0];
			$triggerid = (int) ($event['objectid'] ?? 0);
		}

		if ($triggerid <= 0) {
			echo json_encode(['success' => false, 'error' => _('Event or trigger not found')]);
			exit;
		}

		$history_events = [];
		$all_events = API::Event()->get([
			'output' => ['eventid', 'clock', 'value', 'severity', 'name'],
			'source' => EVENT_SOURCE_TRIGGERS,
			'object' => EVENT_OBJECT_TRIGGER,
			'objectids' => [$triggerid],
			'time_from' => strtotime('-12 months'),
			'sortfield' => ['clock', 'eventid'],
			'sortorder' => 'ASC'
		]);

		$pending = [];
		foreach ($all_events as $evt) {
			if ($evt['value'] == 1) {
				$pending[] = ['eventid' => $evt['eventid'], 'clock' => $evt['clock'], 'r_clock' => 0];
			} else {
				if (!empty($pending)) {
					$p = array_shift($pending);
					$p['r_clock'] = $evt['clock'];
					$history_events[] = $p;
				}
			}
		}
		foreach ($pending as $p) {
			$history_events[] = $p;
		}

		$triggers = API::Trigger()->get([
			'output' => ['triggerid'],
			'triggerids' => [$triggerid],
			'selectHosts' => ['hostid']
		]);
		$hostid = !empty($triggers[0]['hosts'][0]['hostid']) ? (int) $triggers[0]['hosts'][0]['hostid'] : 0;
		$host = null;
		if ($hostid > 0) {
			$hosts = API::Host()->get([
				'output' => ['hostid', 'hostgroups'],
				'hostids' => [$hostid],
				'selectHostGroups' => ['groupid']
			]);
			$host = $hosts[0] ?? null;
		}

		$maintenances = $this->getMaintenancesInPeriod($hostid, $host, strtotime('-12 months'), time());

		$pattern_events = $history_events;
		$event_clocks = array_column($pattern_events, 'clock');

		$user_lang = (CWebUser::$data && isset(CWebUser::$data['lang'])) ? CWebUser::$data['lang'] : null;
		$force_24h = in_array($user_lang, ['pt_BR', 'pt_PT']);
		$use_12h = !$force_24h && (strpos(TIME_FORMAT, 'A') !== false || strpos(TIME_FORMAT, 'a') !== false);
		$hour_labels = [];
		for ($h = 0; $h < 24; $h++) {
			$ts_utc = gmmktime($h, 0, 0, 1, 1, 2024);
			$hour_labels[] = $use_12h ? zbx_date2str('g a', $ts_utc, 'UTC') : (zbx_date2str('H', $ts_utc, 'UTC') . _x('h', 'hour short'));
		}

		$weekdays = [_('Sun'), _('Mon'), _('Tue'), _('Wed'), _('Thu'), _('Fri'), _('Sat')];
		$weekly_hourly_details = array_fill(0, 7, array_fill(0, 24, 0));
		$month_keys = [];
		$current_time = time();
		for ($i = 11; $i >= 0; $i--) {
			$ts = strtotime("-$i months", $current_time);
			$month_keys[] = date('Y-m', $ts);
		}
		$monthly_daily_details = [];
		foreach ($month_keys as $mk) {
			$days_in_month = (int) date('t', strtotime($mk . '-01'));
			$monthly_daily_details[$mk] = array_fill(0, 31, 0);
		}

		foreach ($pattern_events as $e) {
			$h = (int) date('G', $e['clock']);
			$w = (int) date('w', $e['clock']);
			$mk = date('Y-m', $e['clock']);
			$day = (int) date('j', $e['clock']);
			$weekly_hourly_details[$w][$h]++;
			if (isset($monthly_daily_details[$mk]) && $day >= 1 && $day <= 31) {
				$monthly_daily_details[$mk][$day - 1]++;
			}
		}

		$monthly_drilldown = [];
		foreach ($month_keys as $mk) {
			$monthly_drilldown[] = array_values($monthly_daily_details[$mk] ?? array_fill(0, 31, 0));
		}

		$total_events = count($pattern_events);
		$slot_count = 7 * 24;
		$avg_per_slot = $total_events > 0 ? $total_events / $slot_count : 0;
		$avg_per_hour = $total_events > 0 ? $total_events / 24 : 0;
		$avg_per_weekday = $total_events > 0 ? $total_events / 7 : 0;
		$avg_per_month = $total_events > 0 && count($month_keys) > 0 ? $total_events / count($month_keys) : 0;

		$monthly_labels = [];
		foreach ($month_keys as $mk) {
			$monthly_labels[] = zbx_date2str('M/y', strtotime($mk . '-01'));
		}

		$chart_data = [
			'success' => true,
			'maintenances' => array_map(function ($m) {
				return ['name' => $m['name'], 'active_since' => (int) $m['active_since'], 'active_till' => (int) $m['active_till']];
			}, $maintenances),
			'hourly' => array_fill(0, 24, 0),
			'weekly' => array_fill(0, 7, 0),
			'monthly' => [],
			'hourLabels' => $hour_labels,
			'weekLabels' => $weekdays,
			'monthLabels' => $monthly_labels,
			'weeklyHourlyDetails' => array_map(function ($arr) {
				return array_values($arr);
			}, $weekly_hourly_details),
			'monthlyDailyDetails' => $monthly_drilldown,
			'monthKeys' => $month_keys,
			'dayLabels' => range(1, 31),
			'eventClocks' => $event_clocks,
			'avgPerSlot' => $avg_per_slot,
			'avgPerHour' => $avg_per_hour,
			'avgPerWeekday' => $avg_per_weekday,
			'avgPerMonth' => $avg_per_month,
			'hintMonth' => _('Click a month to see which days had the most incidents'),
			'hintDay' => _('Click a day to see hourly distribution in heatmap'),
			'hintHour' => _('Click an hour to filter all charts'),
			'filteredBy' => _('Filtered by'),
			'clearFilter' => _('Clear filter'),
			'times' => _('×'),
			'sameSlotLastWeek' => _('Same slot last week'),
			'sameDayLastWeek' => _('Same day last week'),
			'incidents' => _('incidents'),
			'legendMaintenanceBorder' => _('Icon = incident during maintenance'),
			'maintIconClass' => 'zi zi-wrench-alt-small'
		];

		$monthly_counts = array_fill_keys($month_keys, 0);
		foreach ($pattern_events as $e) {
			$chart_data['hourly'][(int) date('G', $e['clock'])]++;
			$chart_data['weekly'][(int) date('w', $e['clock'])]++;
			$mk = date('Y-m', $e['clock']);
			if (isset($monthly_counts[$mk])) {
				$monthly_counts[$mk]++;
			}
		}
		foreach ($month_keys as $mk) {
			$chart_data['monthly'][] = $monthly_counts[$mk];
		}

		echo json_encode($chart_data);
		exit;
	}

	private function getMaintenancesInPeriod(int $hostid, ?array $host, int $time_from, int $time_to): array {
		if ($hostid <= 0 || $host === null || !$this->checkAccess(\CRoleHelper::UI_CONFIGURATION_MAINTENANCE)) {
			return [];
		}
		$groupids = array_column($host['hostgroups'] ?? [], 'groupid');
		$all = [];
		try {
			$by_host = API::Maintenance()->get([
				'output' => ['maintenanceid', 'name', 'description', 'active_since', 'active_till'],
				'selectTimeperiods' => ['timeperiod_type', 'period', 'start_date', 'start_time', 'every', 'day', 'dayofweek', 'month'],
				'hostids' => [$hostid],
				'preservekeys' => true
			]);
			$all = $by_host;
			if (!empty($groupids)) {
				$by_group = API::Maintenance()->get([
					'output' => ['maintenanceid', 'name', 'description', 'active_since', 'active_till'],
					'selectTimeperiods' => ['timeperiod_type', 'period', 'start_date', 'start_time', 'every', 'day', 'dayofweek', 'month'],
					'groupids' => $groupids,
					'preservekeys' => true
				]);
				$all = $all + $by_group;
			}
		} catch (\Throwable $e) {
			return [];
		}

		$result = [];
		foreach ($all as $m) {
			$since = (int) ($m['active_since'] ?? 0);
			$till = (int) ($m['active_till'] ?? 0);
			if ($till >= $time_from && $since <= $time_to) {
				$result[] = [
					'name' => $m['name'] ?? _('Maintenance'),
					'active_since' => $since,
					'active_till' => $till
				];
			}
		}
		return $result;
	}
}
