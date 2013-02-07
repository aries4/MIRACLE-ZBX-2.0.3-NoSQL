<?php
/*
** Zabbix
** Copyright (C) 2000-2012 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * File containing CHistory class for API.
 * @package API
 */
/**
 * Class containing methods for operations with History of Items
 *
 */
class CHistory extends CZBXAPI {

	public function __construct() {
		// considering the quirky nature of the history API,
		// the parent::__construct() method should not be called.
	}

	/**
	 * Get history data
	 *
	 * @param array $options
	 * @param array $options['itemids']
	 * @param boolean $options['editable']
	 * @param string $options['pattern']
	 * @param int $options['limit']
	 * @param string $options['order']
	 * @return array|int item data as array or false if error
	 */
	public function get($options = array()) {
		global $HISTORY_DB;
		if ($HISTORY_DB['USE'] == 'yes') {
			return $this->getByHistoryGluon($options);
		} else {
			return $this->getBySQL($options);
		}
	}

	protected function getBySQL($options = array()) {
		$result = array();
		$nodeCheck = false;

		// allowed columns for sorting
		$sortColumns = array('itemid', 'clock');

		// allowed output options for [ select_* ] params
		$subselectsAllowedOutputs = array(API_OUTPUT_REFER, API_OUTPUT_EXTEND);

		$sqlParts = array(
			'select'	=> array('history' => 'h.itemid'),
			'from'		=> array(),
			'where'		=> array(),
			'group'		=> array(),
			'order'		=> array(),
			'limit'		=> null
		);

		$defOptions = array(
			'history'					=> ITEM_VALUE_TYPE_UINT64,
			'nodeids'					=> null,
			'hostids'					=> null,
			'itemids'					=> null,
			'triggerids'				=> null,
			'editable'					=> null,
			'nopermissions'				=> null,
			// filter
			'filter'					=> null,
			'search'					=> null,
			'searchByAny'				=> null,
			'startSearch'				=> null,
			'excludeSearch'				=> null,
			'searchWildcardsEnabled'	=> null,
			'time_from'					=> null,
			'time_till'					=> null,
			// output
			'output'					=> API_OUTPUT_REFER,
			'countOutput'				=> null,
			'groupCount'				=> null,
			'groupOutput'				=> null,
			'preservekeys'				=> null,
			'sortfield'					=> '',
			'sortorder'					=> '',
			'limit'						=> null
		);
		$options = zbx_array_merge($defOptions, $options);

		switch ($options['history']) {
			case ITEM_VALUE_TYPE_LOG:
				$sqlParts['from']['history'] = 'history_log h';
				$sortColumns[] = 'id';
				break;
			case ITEM_VALUE_TYPE_TEXT:
				$sqlParts['from']['history'] = 'history_text h';
				$sortColumns[] = 'id';
				break;
			case ITEM_VALUE_TYPE_STR:
				$sqlParts['from']['history'] = 'history_str h';
				break;
			case ITEM_VALUE_TYPE_UINT64:
				$sqlParts['from']['history'] = 'history_uint h';
				break;
			case ITEM_VALUE_TYPE_FLOAT:
			default:
				$sqlParts['from']['history'] = 'history h';
		}

		// editable + PERMISSION CHECK
		if (USER_TYPE_SUPER_ADMIN == self::$userData['type'] || $options['nopermissions']) {
		}
		else {
			$itemOptions = array(
				'filter' => array('flags' => array(ZBX_FLAG_DISCOVERY_NORMAL, ZBX_FLAG_DISCOVERY_CREATED)),
				'editable' => $options['editable'],
				'preservekeys' => true,
				'webitems' => true
			);
			if (!is_null($options['itemids'])) {
				$itemOptions['itemids'] = $options['itemids'];
			}
			$items = API::Item()->get($itemOptions);
			$options['itemids'] = array_keys($items);
		}

		// nodeids
		$nodeids = !is_null($options['nodeids']) ? $options['nodeids'] : get_current_nodeid();

		// itemids
		if (!is_null($options['itemids'])) {
			zbx_value2array($options['itemids']);
			$sqlParts['where']['itemid'] = DBcondition('h.itemid', $options['itemids']);

			if (!$nodeCheck) {
				$nodeCheck = true;
				$sqlParts['where'][] = DBin_node('h.itemid', $nodeids);
			}
		}

		// hostids
		if (!is_null($options['hostids'])) {
			zbx_value2array($options['hostids']);

			if ($options['output'] != API_OUTPUT_SHORTEN) {
				$sqlParts['select']['hostid'] = 'i.hostid';
			}
			$sqlParts['from']['items'] = 'items i';
			$sqlParts['where']['i'] = DBcondition('i.hostid', $options['hostids']);
			$sqlParts['where']['hi'] = 'h.itemid=i.itemid';

			if (!$nodeCheck) {
				$nodeCheck = true;
				$sqlParts['where'][] = DBin_node('i.hostid', $nodeids);
			}
		}

		// should be last, after all ****IDS checks
		if (!$nodeCheck) {
			$nodeCheck = true;
			$sqlParts['where'][] = DBin_node('h.itemid', $nodeids);
		}

		// time_from
		if (!is_null($options['time_from'])) {
			$sqlParts['select']['clock'] = 'h.clock';
			$sqlParts['where']['clock_from'] = 'h.clock>='.$options['time_from'];
		}

		// time_till
		if (!is_null($options['time_till'])) {
			$sqlParts['select']['clock'] = 'h.clock';
			$sqlParts['where']['clock_till'] = 'h.clock<='.$options['time_till'];
		}

		// filter
		if (is_array($options['filter'])) {
			zbx_db_filter($sqlParts['from']['history'], $options, $sqlParts);
		}

		// search
		if (is_array($options['search'])) {
			zbx_db_search($sqlParts['from']['history'], $options, $sqlParts);
		}

		// output
		if ($options['output'] == API_OUTPUT_EXTEND) {
			unset($sqlParts['select']['clock']);
			$sqlParts['select']['history'] = 'h.*';
		}

		// countOutput
		if (!is_null($options['countOutput'])) {
			$options['sortfield'] = '';
			$sqlParts['select'] = array('count(DISTINCT h.hostid) as rowscount');

			// groupCount
			if (!is_null($options['groupCount'])) {
				foreach ($sqlParts['group'] as $key => $fields) {
					$sqlParts['select'][$key] = $fields;
				}
			}
		}

		// groupOutput
		$groupOutput = false;
		if (!is_null($options['groupOutput'])) {
			if (str_in_array('h.'.$options['groupOutput'], $sqlParts['select']) || str_in_array('h.*', $sqlParts['select'])) {
				$groupOutput = true;
			}
		}

		// sorting
		zbx_db_sorting($sqlParts, $options, $sortColumns, 'h');

		// limit
		if (zbx_ctype_digit($options['limit']) && $options['limit']) {
			$sqlParts['limit'] = $options['limit'];
		}

		$itemids = array();

		$sqlParts['select'] = array_unique($sqlParts['select']);
		$sqlParts['from'] = array_unique($sqlParts['from']);
		$sqlParts['where'] = array_unique($sqlParts['where']);
		$sqlParts['order'] = array_unique($sqlParts['order']);

		$sqlSelect = '';
		$sqlFrom = '';
		$sqlWhere = '';
		$sqlOrder = '';
		if (!empty($sqlParts['select'])) {
			$sqlSelect .= implode(',', $sqlParts['select']);
		}
		if (!empty($sqlParts['from'])) {
			$sqlFrom .= implode(',', $sqlParts['from']);
		}
		if (!empty($sqlParts['where'])) {
			$sqlWhere .= implode(' AND ', $sqlParts['where']);
		}
		if (!empty($sqlParts['order'])) {
			$sqlOrder .= ' ORDER BY '.implode(',', $sqlParts['order']);
		}
		$sqlLimit = $sqlParts['limit'];

		$sql = 'SELECT '.$sqlSelect.
				' FROM '.$sqlFrom.
				' WHERE '.
					$sqlWhere.
					$sqlOrder;
		$dbRes = DBselect($sql, $sqlLimit);
		$count = 0;
		$group = array();
		while ($data = DBfetch($dbRes)) {
			if ($options['countOutput']) {
				$result = $data;
			}
			else {
				$itemids[$data['itemid']] = $data['itemid'];

				if ($options['output'] == API_OUTPUT_SHORTEN) {
					$result[$count] = array('itemid' => $data['itemid']);
				}
				else {
					$result[$count] = array();

					// hostids
					if (isset($data['hostid'])) {
						if (!isset($result[$count]['hosts'])) {
							$result[$count]['hosts'] = array();
						}
						$result[$count]['hosts'][] = array('hostid' => $data['hostid']);
						unset($data['hostid']);
					}

					// triggerids
					if (isset($data['triggerid'])) {
						if (!isset($result[$count]['triggers'])) {
							$result[$count]['triggers'] = array();
						}
						$result[$count]['triggers'][] = array('triggerid' => $data['triggerid']);
						unset($data['triggerid']);
					}
					$result[$count] += $data;

					// grouping
					if ($groupOutput) {
						$dataid = $data[$options['groupOutput']];
						if (!isset($group[$dataid])) {
							$group[$dataid] = array();
						}
						$group[$dataid][] = $result[$count];
					}
					$count++;
				}
			}
		}

		if (is_null($options['preservekeys'])) {
			$result = zbx_cleanHashes($result);
		}
		return $result;
	}

	protected function getByHistoryGluon($options = array()) {
		// FIXME: most features aren't implemented yet!

		$options = $this->getMergedOptions($options);
		$hasTimeRange = FALSE;

		$items = $this->getItems($options);

		if (is_null($options['time_from'])) {
			$time_from = 0;
		} else {
			$time_from = $options['time_from'];
			$hasTimeRange = TRUE;
		}

		if (is_null($options['time_till'])) {
			$time_till = time(null);
		} else {
			$time_till = $options['time_till'];
			$hasTimeRange = TRUE;
		}
		$time_till += 1;

		$history_gluon = HistoryGluon::getInstance();
		$histories = array();

		foreach ($items as $item) {
			$hglHistories = $history_gluon->getHistory($item['itemid'], $time_from, $time_till);

			if (is_null($hglHistories)) {
				continue;
			}

			foreach ($hglHistories['array'] as $hglHistory) {
				if (!$this->isRequiredValueType($hglHistory['value'], $options['history']))
					continue;

				$num = array_push($histories, array());
				$history = &$histories[$num - 1];

				$history['itemid'] = (string) $hglHistory['id'];
				if ($hasTimeRange) {
					$history['clock'] = (string) $hglHistory['sec'];
				}
				if ($options['output'] == API_OUTPUT_EXTEND) {
					$history['ns'] = (string) $hglHistory['ns'];
					$history['value'] = (string) $hglHistory['value'];
				}
				if (isset($item['hostid'])) {
					$history['hosts'] = array(array('hostid' => $item['hostid']));
				}
				if (isset($item['triggerid'])) {
					$history['triggers'] = array(array('triggerid' => $item['triggerid']));
				}
			}
		}

		if (is_null($options['preservekeys'])) {
			$histories = zbx_cleanHashes($histories);
		}

		if (isset($options['countOutput'])) {
			return array('rowscount' => count($histories));
		} else {
			return $histories;
		}
	}

	protected function isRequiredValueType($value, $requiredType) {
		$actualType = gettype($value);
		switch ($requiredType) {
			case ITEM_VALUE_TYPE_LOG:
				// FIXME: not implemented in src/libs/zbxdbcache/dbcache.c
				return FALSE;
			case ITEM_VALUE_TYPE_TEXT:
				// FIXME: not implemented in src/libs/zbxdbcache/dbcache.c
				return FALSE;
			case ITEM_VALUE_TYPE_STR:
				return ($actualType == 'string');
			case ITEM_VALUE_TYPE_UINT64:
				return ($actualType == 'integer');
			case ITEM_VALUE_TYPE_FLOAT:
			default:
				return ($actualType == 'double');
		}
	}

	protected function getMergedOptions($options = array()) {
		$defOptions = array(
			'history'					=> ITEM_VALUE_TYPE_UINT64,
			'nodeids'					=> null,
			'hostids'					=> null,
			'itemids'					=> null,
			'triggerids'				=> null,
			'editable'					=> null,
			'nopermissions'				=> null,
			// filter
			'filter'					=> null,
			'search'					=> null,
			'searchByAny'				=> null,
			'startSearch'				=> null,
			'excludeSearch'				=> null,
			'searchWildcardsEnabled'	=> null,
			'time_from'					=> null,
			'time_till'					=> null,
			// output
			'output'					=> API_OUTPUT_REFER,
			'countOutput'				=> null,
			'groupCount'				=> null,
			'groupOutput'				=> null,
			'preservekeys'				=> null,
			'sortfield'					=> '',
			'sortorder'					=> '',
			'limit'						=> null
		);
		return zbx_array_merge($defOptions, $options);
	}

	protected function getItems($options) {
		$itemsQueryOptions = $this->getItemsQueryOptions($options);

		if (is_null($options['itemids'])) {
			return API::Item()->get($itemsQueryOptions);
		}

		zbx_value2array($options['itemids']);

		if (count($itemsQueryOptions) > 0) {
			$givenItemIds = array();
			foreach ($options['itemids'] as $itemid) {
				$givenItemIds[$itemid] = TRUE;
			}

			$itemIdFilter = function($var){
				return $givenItemIds[$var['itemid']];
			};

			$items = API::Item()->get($itemsQueryOptions);
			return array_filter($items, $itemIdFilter);
		} else {
			$items = array();
			foreach ($options['itemids'] as $itemid) {
				array_push($items, array('itemid' => $itemid));
			}
			return $items;
		}
	}

	protected function getItemsQueryOptions($options) {
		// FIXME: implement remaining options
		$knownOptionKeys = array('hostids', 'nodeids', 'triggerids');
		$itemsQueryOptions = array();
		foreach ($knownOptionKeys as $key) {
			if (isset($options[$key])) {
				$itemsQueryOptions[$key] = $options[$key];
			}
		}
		return $itemsQueryOptions;
	}

	public function create($items = array()) {
	}

	public function delete($itemids = array()) {
	}
}
