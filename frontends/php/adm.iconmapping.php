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
?>
<?php
require_once dirname(__FILE__).'/include/config.inc.php';

$page['title'] = _('Configuration of icon mapping');
$page['file'] = 'adm.iconmapping.php';
$page['hist_arg'] = array();

require_once dirname(__FILE__).'/include/page_header.php';

// VAR	TYPE	OPTIONAL	FLAGS	VALIDATION	EXCEPTION
$fields = array(
	'iconmapid' =>		array(T_ZBX_INT, O_OPT, P_SYS,			DB_ID,	'(isset({form})&&({form}=="update"))||isset({delete})'),
	'iconmap' =>		array(T_ZBX_STR, O_OPT, null,			null,	'isset({save})'),
	'save' =>			array(T_ZBX_STR, O_OPT, P_SYS|P_ACT,	null,	null),
	'delete' =>			array(T_ZBX_STR, O_OPT, P_SYS|P_ACT,	null,	null),
	'clone' =>			array(T_ZBX_STR, O_OPT, null,			null,	null),
	'form' =>			array(T_ZBX_STR, O_OPT, P_SYS,			null,	null),
	'form_refresh' =>	array(T_ZBX_INT, O_OPT, null,			null,	null)
);
check_fields($fields);

/*
 * Actions
 */
if (isset($_REQUEST['save'])) {
	$_REQUEST['iconmap']['mappings'] = isset($_REQUEST['iconmap']['mappings'])
		? $_REQUEST['iconmap']['mappings']
		: array();

	$i = 0;
	foreach ($_REQUEST['iconmap']['mappings'] as $iconmappingid => &$mapping) {
		$mapping['iconmappingid'] = $iconmappingid;
		$mapping['sortorder'] = $i++;
	}
	unset($mapping);

	if (isset($_REQUEST['iconmapid'])) {
		$_REQUEST['iconmap']['iconmapid'] = $_REQUEST['iconmapid'];
		$result = API::IconMap()->update($_REQUEST['iconmap']);
		$msgOk = _('Icon map updated');
		$msgErr = _('Cannot update icon map');
	}
	else {
		$result = API::IconMap()->create($_REQUEST['iconmap']);
		$msgOk = _('Icon map created');
		$msgErr = _('Cannot create icon map');
	}

	show_messages($result, $msgOk, $msgErr);
	if ($result) {
		unset($_REQUEST['form']);
	}
}
elseif (isset($_REQUEST['delete'])) {
	$result = API::IconMap()->delete($_REQUEST['iconmapid']);
	if ($result) {
		unset($_REQUEST['form']);
	}
	show_messages($result, _('Icon map deleted'), _('Cannot delete icon map'));
}
elseif (isset($_REQUEST['clone'])) {
	unset($_REQUEST['iconmapid']);
	$_REQUEST['form'] = 'clone';
}

/*
 * Display
 */
$form = new CForm();
$form->cleanItems();
$cmbConf = new CComboBox('configDropDown', 'adm.iconmapping.php', 'redirect(this.options[this.selectedIndex].value);');
$cmbConf->addItems(array(
	'adm.gui.php' => _('GUI'),
	'adm.housekeeper.php' => _('Housekeeper'),
	'adm.images.php' => _('Images'),
	'adm.iconmapping.php' => _('Icon mapping'),
	'adm.regexps.php' => _('Regular expressions'),
	'adm.macros.php' => _('Macros'),
	'adm.valuemapping.php' => _('Value mapping'),
	'adm.workingtime.php' => _('Working time'),
	'adm.triggerseverities.php' => _('Trigger severities'),
	'adm.triggerdisplayoptions.php' => _('Trigger displaying options'),
	'adm.other.php' => _('Other')
));
$form->addItem($cmbConf);
if (!isset($_REQUEST['form'])) {
	$form->addItem(new CSubmit('form', _('Create icon map')));
}

$cnf_wdgt = new CWidget();
$cnf_wdgt->addPageHeader(_('CONFIGURATION OF ICON MAPPING'), $form);

$data = array();
$data['form_refresh'] = get_request('form_refresh', 0);
$data['iconmapid'] = get_request('iconmapid');

$data['iconList'] = array();
$iconList = API::Image()->get(array(
	'filter' => array('imagetype' => IMAGE_TYPE_ICON),
	'output' => API_OUTPUT_EXTEND,
	'preservekeys' => true
));
foreach ($iconList as $icon) {
	$data['iconList'][$icon['imageid']] = $icon['name'];
}

$data['inventoryList'] = array();
$inventoryFields = getHostInventories();
foreach ($inventoryFields as $field) {
	$data['inventoryList'][$field['nr']] = $field['title'];
}

if (isset($_REQUEST['form'])) {
	if ($data['form_refresh'] || ($_REQUEST['form'] === 'clone')) {
		$data['iconmap'] = get_request('iconmap');
	}
	elseif (isset($_REQUEST['iconmapid'])) {
		$iconMap = API::IconMap()->get(array(
			'output' => API_OUTPUT_EXTEND,
			'iconmapids' => $_REQUEST['iconmapid'],
			'editable' => true,
			'preservekeys' => true,
			'selectMappings' => API_OUTPUT_EXTEND,
		));
		$data['iconmap'] = reset($iconMap);
	}
	else {
		$firstIcon = reset($iconList);
		$data['iconmap'] = array(
			'name' => '',
			'default_iconid' => $firstIcon['imageid'],
			'mappings' => array(),
		);
	}

	$iconMapView = new CView('administration.general.iconmap.edit', $data);
}
else {
	$cnf_wdgt->addHeader(_('Icon mapping'));
	$data['iconmaps'] = API::IconMap()->get(array(
		'output' => API_OUTPUT_EXTEND,
		'editable' => true,
		'preservekeys' => true,
		'selectMappings' => API_OUTPUT_EXTEND,
	));
	order_result($data['iconmaps'], 'name');
	$iconMapView = new CView('administration.general.iconmap.list', $data);
}

$cnf_wdgt->addItem($iconMapView->render());
$cnf_wdgt->show();

require_once dirname(__FILE__).'/include/page_footer.php';
?>
