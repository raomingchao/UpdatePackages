<?php
/**
 * YetiForceUpdate Class.
 *
 * @package   YetiForce.UpdatePackages
 *
 * @copyright YetiForce Sp. z o.o.
 * @license   YetiForce Public License 3.0 (licenses/LicenseEN.txt or yetiforce.com)
 * @author    Radosław Skrzypczak <r.skrzypczak@yetiforce.com>
 */
// last check: 46af284fe479b1824e876fd148083a417f52c0b9
/**
 * YetiForceUpdate Class.
 */
class YetiForceUpdate
{
	/**
	 * @var string
	 */
	public $logFile = 'cache/logs/updateLogsTrace.log';
	/**
	 * @var \vtlib\PackageImport
	 */
	public $package;

	/**
	 * @var object
	 */
	public $modulenode;

	/**
	 * Fields to delete.
	 *
	 * @var string[]
	 */
	public $filesToDelete = [];

	/**
	 * DbImporter.
	 *
	 * @var DbImporter
	 */
	private $importer;

	/**
	 * Constructor.
	 *
	 * @param object $modulenode
	 */
	public function __construct($modulenode)
	{
		$this->modulenode = $modulenode;
		$this->filesToDelete = require_once 'deleteFiles.php';
	}

	/**
	 * Logs.
	 *
	 * @param string $message
	 */
	public function log($message)
	{
		$fp = fopen($this->logFile, 'a+');
		fwrite($fp, $message . PHP_EOL);
		fclose($fp);
	}

	/**
	 * Preupdate.
	 */
	public function preupdate()
	{
		$minTime = 600;
		if (ini_get('max_execution_time') < $minTime || ini_get('max_input_time') < $minTime) {
			$this->package->_errorText = 'The server configuration is not compatible with the requirements of the upgrade package.' . PHP_EOL;
			$this->package->_errorText .= 'Please have a look at the list of errors:';
			if (ini_get('max_execution_time') < $minTime) {
				$this->package->_errorText .= PHP_EOL . 'max_execution_time = ' . ini_get('max_execution_time') . ' < ' . $minTime;
			}
			if (ini_get('max_input_time') < $minTime) {
				$this->package->_errorText .= PHP_EOL . 'max_input_time = ' . ini_get('max_input_time') . ' < ' . $minTime;
			}
			return false;
		}
		return true;
	}

	/**
	 * Update.
	 */
	public function update()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));
		$db = \App\Db::getInstance();
		$db->createCommand()->checkIntegrity(false)->execute();
		try {
			$this->importer = new \App\Db\Importer();
			$this->importer->loadFiles(__DIR__ . '/dbscheme');
			$this->importer->updateScheme();
			$this->importer->dropColumns([
				['w_#__servers', 'accounts_id'],
			]);
			$this->importer->refreshSchema();
			$this->importer->postUpdate();
			$this->importer->dropTable(['vtiger_vendorcontactrel']);
			$this->importer->logs(false);
		} catch (\Throwable $ex) {
			$this->log($ex->getMessage() . '|' . $ex->getTraceAsString());
			$this->importer->logs(false);
			throw $ex;
		}
		$this->importer->refreshSchema();
		$db->createCommand()->checkIntegrity(true)->execute();
		$this->updateScheme();
		$this->updateData();
		$this->updateFields();
		$this->addPicklistValues();
		$this->addBlocks();
		$this->addFields();
		$this->setRelations();
		$this->actionMapp();
		$this->updateFaq();
		$this->updatePayments();
		$this->updateInvoice();
		$this->updateProducts();
		$this->updateServices();
		$this->updateNextData();
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function dropTableIfNotUse(string $tableName, array $conditions, bool $drop = false)
	{
		$start = microtime(true);
		$this->log(__METHOD__ . " | {$tableName} |" . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$dropTable = [];
		if (!(new \App\Db\Query())->from('vtiger_field')->where($conditions)->exists() && $db->isTableExists($tableName)) {
			$dropTable[] = $tableName;
			if ($db->isTableExists($tableName . '_seq')) {
				$dropTable[] = $tableName . '_seq';
			}
		}
		if ($drop && $dropTable) {
			$this->importer->dropTable($dropTable);
		}
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
		return $dropTable;
	}

	private function updateFaq()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$importerType = new \App\Db\Importers\Base();
		$queryBuilder = $db->getSchema()->getQueryBuilder();
		$moduleInstance = \Vtiger_Module_Model::getInstance('Faq');
		$mapp = ['question' => 'introduction', 'faq_answer' => 'content', 'faqcategories' => 'category'];
		$templateId = null;
		$fieldModel = $moduleInstance->getFieldByName('faqcategories');
		if ($fieldModel && 'picklist' === $fieldModel->getFieldDataType()) {
			$oldValue = $fieldModel->getPicklistValues(true);
			$treeData = [];
			foreach (array_keys($oldValue) as $value) {
				$treeData[] = [0, $value, 'T1', 'T1', 0, $value, '{"loaded":"1","opened":false,"selected":false,"disabled":false}', ''];
			}
			$templateId = $this->setTree([
				'column' => 'category',
				'base' => [30, 'Category', $moduleInstance->getId(), 0],
				'data' => $treeData
			]);
		}
		if (!isset($moduleInstance->getFieldsByBlocks()['LBL_ARTICLE'])) {
			$blockInstance = new \vtlib\Block();
			$blockInstance->label = 'LBL_ARTICLE';
			$moduleInstance->addBlock($blockInstance);
		}
		$fields = [
			'subject' => [15, 2794, 'subject', 'vtiger_faq', 2, 1, 'subject', 'FL_SUBJECT', 0, 2, '', '255', 1, 37, 1, 'V~O~LE~255', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0,
				'type' => $importerType->stringType(255)->defaultValue(''), 'blockLabel' => 'LBL_FAQ_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Faq'],

			'content' => [15, 2795, 'content', 'vtiger_faq', 1, 300, 'content', 'FL_CONTENT', 0, 2,'' , '16777215', 1, 442, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0,
				'type' => $importerType->mediumText(), 'blockLabel' => 'LBL_ARTICLE', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Faq'],

			'category' => [15, 2796, 'category', 'vtiger_faq', 1, 302, 'category', 'FL_CATEGORY', 0, 2, '', '30', 3, 37, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, $templateId, null, 0, 0, 0,
				'type' => $importerType->stringType(30)->defaultValue(''), 'blockLabel' => 'LBL_FAQ_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Faq'],

			'featured' => [15, 2797, 'featured', 'vtiger_faq', 1, 56, 'featured', 'FL_FEATURED', 0, 2, '', '-128,127', 6, 37, 1, 'C~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0,
				'type' => $importerType->tinyInteger(1)->defaultValue(0), 'blockLabel' => 'LBL_FAQ_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Faq'],

			'introduction' => [15, 2798, 'introduction', 'vtiger_faq', 1, 300, 'introduction', 'FL_INTRODUCTION', 0, 2, '', '65535', 2, 442, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0,	'type' => $importerType->text(), 'blockLabel' => 'LBL_ARTICLE', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Faq'],

			'knowledgebase_view' => [15, 2799, 'knowledgebase_view', 'vtiger_faq', 1, 16, 'knowledgebase_view', 'FL_VIEWS', 0, 2, '', '255', 4, 37, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->stringType(255)->defaultValue(''), 'blockLabel' => 'LBL_FAQ_INFORMATION', 'picklistValues' => ['PLL_PAGE', 'PLL_PRESENTATION'], 'relatedModules' => [], 'moduleName' => 'Faq'],

			'accountid' => [15, 2821, 'accountid', 'vtiger_faq', 1, 10, 'accountid', 'FL_ACCOUNT', 0, 2, '', '4294967295', 8, 37, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0,
				'type' => $importerType->int(11)->unsigned(), 'blockLabel' => 'LBL_FAQ_INFORMATION', 'picklistValues' => [], 'relatedModules' => ['Accounts'], 'moduleName' => 'Faq']
		];

		$transaction = $db->beginTransaction();
		try {
			$tableName = $moduleInstance->basetable;
			foreach ($mapp as $fromField => $toField) {
				$data = [];
				if ($fieldModel = $moduleInstance->getFieldByName($fromField)) {
					if ('category' === $toField) {
						$dataCategory = [];
						$dataReader = (new \App\Db\Query())->select(['name', 'tree'])->from('vtiger_trees_templates_data')->where(['templateid' => $templateId])->createCommand()->query();
						while ($row = $dataReader->read()) {
							$dataCategory[] = [$fieldModel->getTableName(), [$fieldModel->getColumnName() => $row['tree']], [$fieldModel->getColumnName() => $row['name']]];
						}
						$dataReader->close();
						\App\Db\Updater::batchUpdate($dataCategory);
					}
					$type = $queryBuilder->getColumnType($fields[$toField]['type']);
					$db->createCommand("ALTER TABLE {$tableName} CHANGE {$fieldModel->getColumnName()} {$toField} {$type};")->execute();
					$fieldData = $fields[$toField];
					$data[] = ['vtiger_field', ['columnname' => $fieldData[2], 'generatedtype' => $fieldData[4], 'uitype' => $fieldData[5], 'fieldname' => $fieldData[6], 'fieldlabel' => $fieldData[7], 'readonly' => $fieldData[8], 'presence' => $fieldData[9], 'defaultvalue' => $fieldData[10], 'maximumlength' => $fieldData[11], 'displaytype' => $fieldData[14], 'typeofdata' => $fieldData[15], 'quickcreate' => $fieldData[16], 'quickcreatesequence' => $fieldData[17], 'info_type' => $fieldData[18], 'masseditable' => $fieldData[19], 'helpinfo' => $fieldData[20], 'summaryfield' => $fieldData[21], 'fieldparams' => $fieldData[22], 'header_field' => $fieldData[23], 'maxlengthtext' => $fieldData[24], 'maxwidthcolumn' => $fieldData[25]], ['fieldid' => $fieldModel->getId()]];
					\App\Db\Updater::batchUpdate($data);
					$this->removeField($fieldModel, $toField);
				}
			}
			if (($fieldModel = $moduleInstance->getFieldByName('comments')) && !$fieldModel->isActiveField()) {
				$this->removeField($fieldModel);
			}
			$this->dropTableIfNotUse('vtiger_faqcomments', ['tablename' => $tableName], true);
			$this->addFields($fields);
			$dbIndexes = $db->getTableKeys($tableName);
			if (!isset($dbIndexes['search']) && 'mysql' === $db->getDriverName()) {
				$db->createCommand("ALTER TABLE {$tableName} ADD FULLTEXT KEY `search` (`subject`,`content`,`introduction`);")->execute();
			}
			$transaction->commit();
		} catch (\Throwable $ex) {
			$transaction->rollBack();
			$this->log('[ERROR] ' . $ex->__toString());
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function updatePayments()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$importerType = new \App\Db\Importers\Base();
		$queryBuilder = $db->getSchema()->getQueryBuilder();
		$moduleInstance = \Vtiger_Module_Model::getInstance('PaymentsIn');

		$transaction = $db->beginTransaction();
		try {
			$fieldModel = $moduleInstance->getFieldByName('paymentscurrency');
			if ($fieldModel) {
				$newField = 'currency_id';
				$importerType = new \App\Db\Importers\Base();
				$queryBuilder = $db->getSchema()->getQueryBuilder();
				$type = $queryBuilder->getColumnType($importerType->integer(10));
				$db->createCommand("ALTER TABLE {$fieldModel->getTableName()} CHANGE {$fieldModel->getColumnName()} {$newField} {$type};")->execute();
				$db->createCommand->update('vtiger_field', ['columnname' => $newField, 'fieldname' => $newField], ['fieldid' => $fieldModel->getId()])->execute();
				$this->removeField($fieldModel, $newField);
			}
			$fields = [
				'payment_system' => [
					79, 2802, 'payment_system', 'vtiger_paymentsin', 1, 16, 'payment_system', 'FL_PAYMENT_SYSTEM', 1, 2, '', '64', 5, 251, 10, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->stringType(255), 'blockLabel' => 'LBL_PAYMENT_INFORMATION', 'picklistValues' => ['PLL_REDSYS', 'PLL_DOTPAY'], 'relatedModules' => [], 'moduleName' => 'PaymentsIn'
				],
				'transaction_id' => [
					79, 2803, 'transaction_id', 'vtiger_paymentsin', 1, 1, 'transaction_id', 'FL_TRANSACTION', 1, 2, '', '255', 6, 251, 10, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->stringType(255), 'blockLabel' => 'LBL_PAYMENT_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'PaymentsIn'
				],
				'ssingleordersid' => [
					79, 2804, 'ssingleordersid', 'vtiger_paymentsin', 1, 10, 'ssingleordersid', 'FL_ORDER', 1, 2, '', '-2147483648,2147483647', 7, 251, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->stringType(255), 'blockLabel' => 'LBL_PAYMENT_INFORMATION', 'picklistValues' => [], 'relatedModules' => ['SSingleOrders'], 'moduleName' => 'PaymentsIn'
				],
				'finvoiceid' => [
					79, 2805, 'finvoiceid', 'vtiger_paymentsin', 1, 10, 'finvoiceid', 'FL_INVOICE', 1, 2, '', '-2147483648,2147483647', 8, 251, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->stringType(255), 'blockLabel' => 'LBL_PAYMENT_INFORMATION', 'picklistValues' => [], 'relatedModules' => ['FInvoice'], 'moduleName' => 'PaymentsIn'
				],
			];
			$this->addFields($fields);
			\App\EventHandler::registerHandler('EntityAfterSave', 'PaymentsIn_PaymentsInHandler_Handler', $moduleInstance->getName());
			$transaction->commit();
		} catch (\Throwable $ex) {
			$transaction->rollBack();
			$this->log('[ERROR] ' . $ex->__toString());
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function updateInvoice()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$importerType = new \App\Db\Importers\Base();
		$queryBuilder = $db->getSchema()->getQueryBuilder();
		$moduleInstance = \Vtiger_Module_Model::getInstance('PaymentsIn');

		$transaction = $db->beginTransaction();
		try {
			$mapp = ['finvoice_paymentstatus' => 'payment_status'];
			$fields = [
				'ssalesprocessesid' => [
					95, 2786, 'ssalesprocessesid', 'u_yf_finvoice', 1, 10, 'ssalesprocessesid', 'FL_OPPORTUNITY', 0, 2, '', null, 12, 310, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->integer(10), 'blockLabel' => 'LBL_BASIC_DETAILS', 'picklistValues' => [], 'relatedModules' => ['SSalesProcesses'], 'moduleName' => 'FInvoice'
				],
				'projectid' => [
					95, 2787, 'projectid', 'u_yf_finvoice', 1, 10, 'projectid', 'FL_PROJECT', 0, 2, '', null, 13, 310, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->integer(10), 'blockLabel' => 'LBL_BASIC_DETAILS', 'picklistValues' => [], 'relatedModules' => ['Project'], 'moduleName' => 'FInvoice'
				],
				'payment_status' => [
					95, 2825, 'payment_status', 'u_yf_finvoice', 1, 15, 'payment_status', 'FL_PAYMENT_STATUS', 1, 2, 'PLL_NOT_PAID', '255', 14, 310, 10, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->stringType(255), 'blockLabel' => 'LBL_BASIC_DETAILS', 'picklistValues' => ['PLL_NOT_PAID', 'PLL_UNDERPAID', 'PLL_PAID', 'PLL_OVERPAID'], 'relatedModules' => [], 'moduleName' => 'FInvoice'
				],
			];

			foreach ($mapp as $fromField => $toField) {
				$data = [];
				if ($fieldModel = $moduleInstance->getFieldByName($fromField)) {
					$type = $queryBuilder->getColumnType($fields[$toField]['type']);
					$db->createCommand("ALTER TABLE {$fieldModel->getTableName()} CHANGE {$fieldModel->getColumnName()} {$toField} {$type};")->execute();
					$fieldData = $fields[$toField];
					$data[] = ['vtiger_field', ['columnname' => $fieldData[2], 'generatedtype' => $fieldData[4], 'uitype' => $fieldData[5], 'fieldname' => $fieldData[6], 'fieldlabel' => $fieldData[7], 'readonly' => $fieldData[8], 'presence' => $fieldData[9], 'defaultvalue' => $fieldData[10], 'maximumlength' => $fieldData[11], 'displaytype' => $fieldData[14], 'typeofdata' => $fieldData[15], 'quickcreate' => $fieldData[16], 'quickcreatesequence' => $fieldData[17], 'info_type' => $fieldData[18], 'masseditable' => $fieldData[19], 'helpinfo' => $fieldData[20], 'summaryfield' => $fieldData[21], 'fieldparams' => $fieldData[22], 'header_field' => $fieldData[23], 'maxlengthtext' => $fieldData[24], 'maxwidthcolumn' => $fieldData[25]], ['fieldid' => $fieldModel->getId()]];
					\App\Db\Updater::batchUpdate($data);
					$this->removeField($fieldModel, $toField);
					if ('payment_status' === $toField) {
						$dataUpdate = [];
						foreach (['PLL_AWAITING_PAYMENT' => 'PLL_NOT_PAID', 'PLL_PARTIALLY_PAID' => 'PLL_UNDERPAID', 'PLL_FULLY_PAID' => 'PLL_PAID'] as $oldValue => $newValue) {
							$dataUpdate[] = [$fieldModel->getTableName(), [$fieldModel->getColumnName() => $newValue], [$fieldModel->getColumnName() => $oldValue]];
						}
						\App\Db\Updater::batchUpdate($dataUpdate);
					}
				}
			}

			$this->addFields($fields);
			$transaction->commit();
		} catch (\Throwable $ex) {
			$transaction->rollBack();
			$this->log('[ERROR] ' . $ex->__toString());
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function updateProducts()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$importerType = new \App\Db\Importers\Base();

		$transaction = $db->beginTransaction();
		try {
			$fields = [
				'category_multipicklist' => [
					14, 2357, 'category_multipicklist', 'vtiger_products', 1, 309, 'category_multipicklist', 'LBL_CATEGORY_MULTIPICKLIST', 0, 2, null, '65535', 31, 31, 1, 'V~O', 1, null, 'BAS', 1, '', 0, '31', null, 0, 0, 0, 'type' => $importerType->text(), 'blockLabel' => 'LBL_PRODUCT_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Products'
				],
				'purchase' => [
					14, 2817, 'purchase', 'vtiger_products', 1, 360, 'purchase', 'FL_PURCHASE', 0, 2, '', '65535', 6, 32, 1, 'V~O', 2, 3, 'BAS', 0, '', 1, '', null, 0, 0, 0, 'type' => $importerType->text(), 'blockLabel' => 'LBL_PRICING_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Products'
				],
				'unit_price' => [
					14, 193, 'unit_price', 'vtiger_products', 1, 360, 'unit_price', 'Unit Price', 0, 0, '', '65535', 1, 32, 1, 'V~O', 2, 3, 'BAS', 0, '', 1, '', null, 0, 0, 0, 'type' => $importerType->text(), 'blockLabel' => 'LBL_PRICING_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Products'
				],
				'weight' => [
					14, 2824, 'weight', 'vtiger_products', 1, 7, 'weight', 'FL_WEIGHT', 0, 2, '', null, 8, 33, 1, 'NN~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->decimal(11, 3), 'blockLabel' => 'LBL_STOCK_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Products'
				],
				'commissionrate' => [
					14, 194, 'commissionrate', 'vtiger_products', 1, 365, 'commissionrate', 'Commission Rate', 0, 2, '', '99999', 2, 32, 2, 'NN~O', 1, null, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->decimal(8, 3), 'blockLabel' => 'LBL_PRICING_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Products'
				],
			];
			$this->updateProductAndServiceFiels('Products', $fields);
			\App\EventHandler::registerHandler('EntityBeforeSave', 'Products_Calculations_Handler', 'Products,Services', '', 5, true, \App\Module::getModuleId('Products'));
			$transaction->commit();
		} catch (\Throwable $ex) {
			$transaction->rollBack();
			$this->log('[ERROR] ' . $ex->__toString());
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function updateServices()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$importerType = new \App\Db\Importers\Base();
		$queryBuilder = $db->getSchema()->getQueryBuilder();
		$moduleInstance = \Vtiger_Module_Model::getInstance('PaymentsIn');

		$transaction = $db->beginTransaction();
		try {
			$fields = [
				'purchase' => [
					35, 2819, 'purchase', 'vtiger_service', 1, 360, 'purchase', 'FL_COST', 0, 2, '', '65535', 4, 92, 1, 'V~O', 2, 3, 'BAS', 0, '', 1, '', null, 0, 0, 0, 'type' => $importerType->text(), 'blockLabel' => 'LBL_PRICING_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Products'
				],
				'unit_price' => [
					35, 575, 'unit_price', 'vtiger_service', 1, 360, 'unit_price', 'Price', 0, 0, '', '65535', 1, 92, 1, 'V~O', 2, 2, 'BAS', 0, '', 1, '', null, 0, 0, 0, 'type' => $importerType->text(), 'blockLabel' => 'LBL_PRICING_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Products'
				],
				'commissionrate' => [
					35, 577, 'commissionrate', 'vtiger_service', 1, 365, 'commissionrate', 'Commission Rate', 0, 2, '', '99999', 2, 92, 2, 'NN~O', 1, null, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->decimal(8, 3), 'blockLabel' => 'LBL_PRICING_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Products'
				],
			];
			$this->updateProductAndServiceFiels('Services', $fields);
			$this->addFields($fields);
			$this->importer->dropTable(['vtiger_productcurrencyrel']);
			$transaction->commit();
		} catch (\Throwable $ex) {
			$transaction->rollBack();
			$this->log('[ERROR] ' . $ex->__toString());
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function updateProductAndServiceFiels($moduleName, $fields)
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$queryBuilder = $db->getSchema()->getQueryBuilder();
		$moduleInstance = \Vtiger_Module_Model::getInstance($moduleName);
		$mapp = ['unit_price' => 'multiCurrency', 'commissionrate' => 'advPercentage'];
		foreach ($mapp as $fromField => $dataType) {
			$data = [];
			if (($fieldModel = $moduleInstance->getFieldByName($fromField)) && $dataType !== $fieldModel->getFieldDataType()) {
				$type = $queryBuilder->getColumnType($fields[$fromField]['type']);
				$db->createCommand("ALTER TABLE {$fieldModel->getTableName()} CHANGE {$fieldModel->getColumnName()} {$fromField} {$type};")->execute();
				$fieldData = $fields[$fromField];
				$data[] = ['vtiger_field', ['columnname' => $fieldData[2], 'generatedtype' => $fieldData[4], 'uitype' => $fieldData[5], 'fieldname' => $fieldData[6], 'fieldlabel' => $fieldData[7], 'readonly' => $fieldData[8], 'presence' => $fieldData[9], 'defaultvalue' => $fieldData[10], 'maximumlength' => $fieldData[11], 'displaytype' => $fieldData[14], 'typeofdata' => $fieldData[15], 'quickcreate' => $fieldData[16], 'quickcreatesequence' => $fieldData[17], 'info_type' => $fieldData[18], 'masseditable' => $fieldData[19], 'helpinfo' => $fieldData[20], 'summaryfield' => $fieldData[21], 'fieldparams' => $fieldData[22], 'header_field' => $fieldData[23], 'maxlengthtext' => $fieldData[24], 'maxwidthcolumn' => $fieldData[25]], ['fieldid' => $fieldModel->getId()]];
				\App\Db\Updater::batchUpdate($data);
				$this->removeField($fieldModel, $fromField);
				if ('unit_price' === $fromField) {
					$tableName = $moduleInstance->basetable;
					$index = $moduleInstance->basetableid;
					$dataReader = (new \App\Db\Query())->select(['currency_id', $index])->from($tableName)->createCommand()->query();
					while ($row = $dataReader->read()) {
						$currencyData = [];
						$dataReaderRel = (new \App\Db\Query())->select(['currency_id', 'actual_price'])->from('vtiger_productcurrencyrel')->where(['productid' => $row[$index]])->createCommand()->query();
						while ($rowRel = $dataReaderRel->read()) {
							$currencyData['currencies'][(int) $rowRel['currency_id']]['price'] = $rowRel['actual_price'];
						}
						if ($currencyData) {
							$currencyData['currencyId'] = (int) $row['currency_id'];
						}
						$dataReaderRel->close();
						$db->createCommand->update($fieldModel->getTableName(), [$fieldModel->getColumnName() => \App\Json::encode($currencyData)], [$index => $row['productid']])->execute();
					}
					$dataReader->close();
					$this->importer->dropColumns([[$tableName, 'currency_id']]);
				}
			}
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function updateNextData()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$this->statusActivate('HelpDesk', 'ticketstatus');
		\App\EventHandler::registerHandler('EntityAfterSave', 'Vtiger_RecordFlowUpdater_Handler', 'Project,ProjectMilestone,ProjectTask');
		\App\EventHandler::registerHandler('EntityAfterDelete', 'Vtiger_RecordFlowUpdater_Handler', 'Project,ProjectMilestone,ProjectTask');
		\App\EventHandler::registerHandler('EntityChangeState', 'Vtiger_RecordFlowUpdater_Handler', 'Project,ProjectMilestone,ProjectTask');

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}
	private  function statusActivate(string $moduleName, string $fieldName): bool
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));
		$moduleModel = \Vtiger_Module_Model::getInstance($moduleName);
		if (!($fieldModel = $moduleModel->getFieldByName($fieldName))) {
			return false;
		}
		$db = \App\Db::getInstance();
		$schema = $db->getSchema();
		$dbCommand = $db->createCommand();
		$transaction = $db->beginTransaction();
		try {
			$params = $fieldModel->getFieldParams();
			$params['isProcessStatusField'] = true;
			$fieldModel->set('fieldparams', Json::encode($params));
			$fieldModel->save();
			$tableStatusHistory = $moduleModel->get('basetable') . '_state_history';
			if (!$db->getTableSchema($tableStatusHistory)) {
				$db->createTable($tableStatusHistory, [
					'id' => \yii\db\Schema::TYPE_UPK,
					'crmid' => $schema->createColumnSchemaBuilder(\yii\db\Schema::TYPE_INTEGER, 11),
					'before' => $schema->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TINYINT, 1)->notNull()->defaultValue(0),
					'after' => $schema->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TINYINT, 1)->notNull()->defaultValue(0),
					'date' => $schema->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TIMESTAMP)->null(),
				]);
				$dbCommand->createIndex($tableStatusHistory . '_crmid_idx', $tableStatusHistory, 'crmid')->execute();
				$dbCommand->addForeignKey('fk_1_' . $tableStatusHistory, $tableStatusHistory, 'crmid', 'vtiger_crmentity', 'crmid', 'CASCADE', 'RESTRICT')->execute();
			}
			$tableName = \App\Fields\Picklist::getPicklistTableName($fieldName);
			$tableSchema = $db->getTableSchema($tableName);
			if (!isset($tableSchema->columns['record_state'])) {
				$dbCommand->addColumn($tableName, 'record_state', $schema->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TINYINT, 1)->notNull()->defaultValue(0))->execute();
			}
			if (!isset($tableSchema->columns['time_counting'])) {
				$dbCommand->addColumn($tableName, 'time_counting', $schema->createColumnSchemaBuilder(\yii\db\Schema::TYPE_TINYINT, 1)->notNull()->defaultValue(0))->execute();
			}
			foreach (\App\EventHandler::getAll(false) as $handler) {
				if ('Vtiger_RecordStatusHistory_Handler' === $handler['handler_class']) {
					$modules = $handler['include_modules'] ? \explode(',', $handler['include_modules']) : [];
					if (!\in_array($moduleName, $modules)) {
						$modules[] = $moduleName;
					}
					\App\EventHandler::update([
						'is_active' => 1,
						'include_modules' => \implode(',', $modules)
					], $handler['eventhandler_id']);
				}
			}
			$this->addFieldsAndBlock($moduleName);
			$transaction->commit();
		} catch (\Throwable $ex) {
			$transaction->rollBack();
			$this->log('[ERROR] ' . $ex->__toString());
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	/**
	 * Add block and fields.
	 *
	 * @param string $moduleName
	 *
	 * @return void
	 */
	private function addFieldsAndBlock(string $moduleName)
	{
		$stateTimeFields = [
			'RangeTime' => [
				'response_range_time' => 'FL_RESPONSE_RANGE_TIME',
				'solution_range_time' => 'FL_SOLUTION_RANGE_TIME',
				'idle_range_time' => 'FL_IDLE_RANGE_TIME',
				'closing_range_time' => 'FL_CLOSING_RANGE_TIME',
			],
			'DateTime' => [
				'response_datatime' => 'FL_RESPONSE_DATE_TIME',
				'solution_datatime' => 'FL_SOLUTION_DATE_TIME',
				'idle_datatime' => 'FL_IDLE_DATE_TIME',
				'closing_datatime' => 'FL_CLOSING_DATE_TIME',
				'response_expected' => 'FL_RESPONSE_EXPECTED',
				'solution_expected' => 'FL_SOLUTION_EXPECTED',
				'idle_expected' => 'FL_IDLE_DATE_EXPECTED',
			]
		];
		$moduleModel = \Settings_LayoutEditor_Module_Model::getInstanceByName($moduleName);
		$blockId = (new \App\Db\Query())->select(['blockid'])->from('vtiger_blocks')->where(['blocklabel' => 'BL_RECORD_STATUS_TIMES', 'tabid' => $moduleModel->getId()])->scalar();
		if (!$blockId) {
			$blockInstance = new \Settings_LayoutEditor_Block_Model();
			$blockInstance->set('label', 'BL_RECORD_STATUS_TIMES');
			$blockId = $blockInstance->save($moduleModel);
		}
		$allFields = $moduleModel->getFields();
		foreach ($stateTimeFields as $type => $fields) {
			foreach ($fields as $name => $label) {
				if (!isset($allFields[$name])) {
					$moduleModel->addField($type, $blockId, [
						'fieldLabel' => $label,
						'fieldName' => $name,
						'fieldTypeList' => 0,
						'generatedtype' => 1,
						'displayType' => 2,
						'helpinfo' => 'Detail'
					]);
				}
			}
		}
	}
	private function updateData()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$data = [
			['vtiger_cron_task', ['handler_file' => 'cron/CardDav.php'], ['handler_file' => 'modules/API/cron/CardDav.php', 'tabid' => \App\Module::getModuleId('FInvoice')]],
			['vtiger_cron_task', ['handler_file' => 'cron/CalDav.php'], ['handler_file' => 'modules/API/cron/CalDav.php', 'tabid' => \App\Module::getModuleId('FInvoice')]],
			['vtiger_field', ['presence' => 2], ['fieldname' => 'crmactivity', 'tablename' => 'vtiger_entity_stats']],
			['vtiger_field', ['maximumlength' => '16777215'], ['fieldname' => 'content', 'tablename' => 'vtiger_ossmailview']],
			['vtiger_field', ['maximumlength' => '16777215'], ['fieldname' => 'orginal_mail', 'tablename' => 'vtiger_ossmailview']],
			['vtiger_field', ['maximumlength' => '64'], ['fieldname' => 'user_name', 'tablename' => 'vtiger_users']],
			['vtiger_relatedlists', ['name' => 'getDependentsList', 'actions' => 'ADD'], ['tabid' => \App\Module::getModuleId('Assets'), 'related_tabid' => \App\Module::getModuleId('HelpDesk')]],
		];
		\App\Db\Updater::batchUpdate($data);

		$data = [
			['vtiger_links', ['linklabel' => 'Delagated Events/To Dos', 'linktype' => 'DASHBOARDWIDGET', 'linkurl' => 'index.php?module=Home&view=ShowWidget&name=AssignedUpcomingCalendarTasks']],
			['vtiger_links', ['linklabel' => 'Delegated (overdue) Events/ToDos', 'linktype' => 'DASHBOARDWIDGET', 'linkurl' => 'index.php?module=Home&view=ShowWidget&name=AssignedOverdueCalendarTasks']]
		];
		$links = $data;
		foreach ($links as $linkData) {
			if ($linkId = (new \App\Db\Query())->select(['linkid'])->from($linkData[0])->where($linkData[1])->scalar()) {
				$data[] = ['vtiger_module_dashboard', ['linkid' => $linkId]];
				$data[] = ['vtiger_module_dashboard_widgets', ['linkid' => $linkId]];
			}
		}
		\App\Db\Updater::batchDelete($data);

		$data = [
			['vtiger_links',
				[
					'tabid' => App\Module::getModuleId('Home'),
					'linktype' => 'DASHBOARDWIDGET',
					'linklabel' => 'LBL_CREATED_BY_ME_BUT_NOT_MINE_OVERDUE_ACTIVITIES',
					'linkurl' => 'index.php?module=Home&view=ShowWidget&name=CreatedNotMineOverdueActivities',
					'linkicon' => '',
					'sequence' => 0
				], ['tabid' => App\Module::getModuleId('Home'), 'linklabel' => 'LBL_CREATED_BY_ME_BUT_NOT_MINE_OVERDUE_ACTIVITIES']
			]
		];
		\App\Db\Updater::batchInsert($data);

		\App\EventHandler::registerHandler('EntityAfterSave', 'Vtiger_RecordStatusHistory_Handler', '', '', 5, false);
		\App\EventHandler::registerHandler('EntityBeforeSave', 'Vtiger_RecordStatusHistory_Handler', '', '', 5, false);

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	public function addPicklistValues()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$updateData = [];
		$fields = [
			'timecontrol_type' => ['OSSTimeControl', 'PLL_UNPAID_LEAVE', ['color' => '#5E666C']],
			'timecontrol_type' => ['OSSTimeControl', 'PLL_SICK_LEAVE', ['color' => '#9900FF']]
		];
		foreach ($fields as $fieldName => $info) {
			$moduleModel = Settings_Picklist_Module_Model::getInstance($info[0]);
			$fieldModel = Settings_Picklist_Field_Model::getInstance($fieldName, $moduleModel);
			$picklistValues = $fieldModel->getPicklistValues(true);
			if (isset($picklistValues[$info[1]])) {
				continue;
			}
			$roleRecordList = Settings_Roles_Record_Model::getAll();
			$rolesSelected = [];
			foreach ($roleRecordList as $roleRecord) {
				$id = $roleRecord->getId();
				if ('H1' !== $id) {
					$rolesSelected[] = $id;
				}
			}
			$moduleModel->addPickListValues($fieldModel, $info[1], $rolesSelected);
			$tableName = $moduleModel->getPickListTableName($fieldModel->getName());
			if (isset($info[2]['color']) && \in_array('color', $db->getTableSchema($tableName)->getColumnNames())) {
				$updateData[] = [$tableName, $info[2], [$fieldModel->getName() => $info[1]]];
			}
		}
		$newColors = ['PLL_USERS' => '1baee2', 'PLL_SYSTEM' => 'FF9800'];
		$colors = (new \App\Db\Query())->select(['notification_type', 'color'])->from('vtiger_notification_type')->createCommand()->queryAllByGroup();
		foreach ($newColors as $label => $color) {
			if (!isset($colors[$label])) {
				$this->log("[ERROR] . No required value {$label} in the notification_type field");
			} elseif (empty($colors[$label])) {
				$updateData[] = ['vtiger_notification_type', ['color' => $color], ['notification_type' => $label]];
			}
		}

		\App\Db\Updater::batchUpdate($updateData);
		static::generate('picklist');

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function updateScheme()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$dropTable = [];
		$dropCustomTables = [
			'vtiger_currencies' => 'currencyid', //ok
			'vtiger_datashare_relatedmodules' => 'datashare_relatedmodule_id', //ok
			// 'eventstatus', //ok  pole nie istnieje słownik to usuniecia
			'vtiger_field' => 'fieldid', //ok
			// 'vtiger_inventoryproductrel' => 'id', //ok    TO VERIFY do usunięcia calosc
			'vtiger_picklist_dependency' => 'id', // OK
			// 'picklistvalues', // NIE OK //zostawic
			// 'progress', //ok  - slownik zamienioony na uitype 9   do usuniecia
			// 'projecttaskprogress', //ok - slownik zamienioony na uitype 9   do usuniecia
			'vtiger_settings_blocks' => 'blockid', //ok
			'vtiger_version' => 'id', //ok
			'vtiger_ws_entity' => 'id', //ok
			'vtiger_ws_operation' => 'operationid', //ok
		];
		foreach (App\Fields\Picklist::getModules() as $pickListTable) {
			$moduleModel = Vtiger_Module_Model::getInstance($pickListTable['tabname']);
			$pickListFields = [];
			foreach ($moduleModel->getFields() as $field) {
				if (\in_array($field->getFieldDataType(), ['picklist', 'multipicklist'])) {
					$pickListFields[] = $field->getName();
				}
			}
			foreach ($pickListFields as $pickListField) {
				$tableName = 'vtiger_' . $pickListField;
				$fieldName = \App\Fields\Picklist::getPickListId($pickListField);
				if ($tableToRemove = $this->getTableSeqToRemove($tableName, $fieldName)) {
					$dropTable[] = $tableToRemove;
				}
			}
		}
		$fields = ['eventstatus', 'progress', 'projecttaskprogress'];
		foreach ($fields as $fieldName) {
			$tableName = 'vtiger_' . $fieldName;
			if ($drop = $this->dropTableIfNotUse($tableName, ['fieldname' => $fieldName, 'uitype' => [16, 15, 33]])) {
				$dropTable = array_merge($dropTable, $drop);
			}
		}
		foreach ($dropCustomTables as $tableName => $fieldName) {
			if ($tableToRemove = $this->getTableSeqToRemove($tableName, $fieldName)) {
				$dropTable[] = $tableToRemove;
			}
		}
		$this->importer->dropTable($dropTable);

		// FULLTEXT
		$this->importer->logs .= "> start updateScheme()\n";
		$dbIndexes = $db->getTableKeys('u_#__knowledgebase');
		try {
			if (!isset($dbIndexes['search']) && 'mysql' === $db->getDriverName()) {
				$this->importer->logs .= '  > create index: u_#__knowledgebase search ... ';
				$db->createCommand('ALTER TABLE u_yf_knowledgebase ADD FULLTEXT KEY `search` (`subject`,`content`,`introduction`);')->execute();
				$this->importer->logs .= "done\n";
			}
		} catch (\Throwable $e) {
			$this->importer->logs .= " | Error(8) [{$e->getMessage()}] in  \n{$e->getTraceAsString()} !!!\n";
		}

		$this->importer->logs(false);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function getTableSeqToRemove($tableName, $fieldName)
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = \App\Db::getInstance();
		$remove = $importerField = false;
		$tableNameSeqToDrop = $tableName . '_seq';
		if ($db->isTableExists($tableName)) {
			$tableSchema = $db->getTableSchema($tableName);
			$column = $tableSchema->getColumn($fieldName);
			if ($column) {
				if (!$column->isPrimaryKey) {
					$importerField = (new \App\Db\Importers\Base())->primaryKey(10)->autoIncrement()->notNull();
				} elseif (!$column->autoIncrement) {
					$importerField = (new \App\Db\Importers\Base())->integer(10)->autoIncrement()->notNull();
				}
				if ($importerField) {
					$result = $db->createCommand()->alterColumn($tableName, $fieldName, $importerField)->execute();
					if (!$result || !is_numeric($result)) {
						$this->log("[ERROR] . Column {$fieldName} in the table {$fieldName} can not be modified. {$result}");
					} else {
						$remove = true;
					}
				} else {
					$remove = true;
				}
			} else {
				$this->log("[ERROR] Column not exists. {$tableName}");
			}
		} else {
			$this->log("[ERROR] Table not exists. {$tableName}");
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
		return $remove && $db->isTableExists($tableNameSeqToDrop) ? $tableNameSeqToDrop : '';
	}

	/**
	 * Add blocks.
	 */
	public function addBlocks()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));
		$columns = ['blockid', 'tabid', 'label', 'sequence', 'showtitle', 'visible', 'increateview', 'ineditview', 'indetailview', 'display_status', 'iscustom'];
		$blocksModules = [
			'Users' => [
				[437, 29, 'LBL_GLOBAL_SEARCH_SETTINGS', 7, 0, 0, 0, 0, 0, 1, 0]
			],
			'KnowledgeBase' => [
				[438, 96, 'LBL_CUSTOM_INFORMATION', 2, 0, 0, 0, 0, 0, 1, 1]
			]
		];
		foreach ($blocksModules as $moduleName => $blocks) {
			$module = \Vtiger_Module_Model::getInstance($moduleName);
			foreach ($blocks as $block) {
				$blockData = array_combine($columns, $block);
				unset($blockData['blockid'],$blockData['tabid']);
				$blockInstance = new \vtlib\Block();
				foreach ($blockData as $key => $value) {
					$blockInstance->{$key} = $value;
				}
				$module->addBlock($blockInstance);
			}
		}
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	/**
	 * Add fields.
	 *
	 * @param mixed $fields
	 */
	public function addFields($fields = [])
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));
		$importerType = new \App\Db\Importers\Base();
		if (empty($fields)) {
			$fields = [
				[29, 2784, 'default_search_module', 'vtiger_users', 1, 301, 'default_search_module', 'FL_DEFAULT_SEARCH_MODULE', 0, 2, '', null, 0, 437, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->stringType(25), 'blockLabel' => 'LBL_GLOBAL_SEARCH_SETTINGS', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Users'],
				[29, 2785, 'default_search_override', 'vtiger_users', 1, 56, 'default_search_override', 'FL_OVERRIDE_SEARCH_MODULE', 0, 2, '', null, 0, 437, 1, 'V~O', 1, 0, 'BAS', 1, 'Edit,Detail,PreferenceDetail', 0, '', null, 0, 0, 0, 'type' => $importerType->tinyInteger(1), 'blockLabel' => 'LBL_GLOBAL_SEARCH_SETTINGS', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Users'],


				[6, 2788, 'pricebook_id', 'vtiger_account', 1, 10, 'pricebook_id', 'LBL_PRICEBOOK', 0, 2, '', null, 27, 9, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->integer(10), 'blockLabel' => 'LBL_ACCOUNT_INFORMATION', 'picklistValues' => [], 'relatedModules' => ['PriceBooks'], 'moduleName' => 'Accounts'],
				[6, 2793, 'check_stock_levels', 'vtiger_account', 1, 56, 'check_stock_levels', 'FL_CHECK_STOCK_LEVELS', 0, 2, '', '-128,127', 0, 439, 1, 'C~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->tinyInteger(1), 'blockLabel' => 'LBL_FOR_THE_PORTAL', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Accounts'],
				[6, 2800, 'sum_open_orders', 'vtiger_account', 1, 71, 'sum_open_orders', 'FL_SUM_ORDERS', 0, 2, '', '9999999999999999999', 2, 439, 2, 'N~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => 'decimal(28,8)', 'blockLabel' => 'LBL_FOR_THE_PORTAL', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Accounts'],
				[6, 2822, 'taxes', 'vtiger_account', 1, 303, 'taxes', 'FL_TAXES', 0, 2, '', '65535', 11, 198, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->text(), 'blockLabel' => 'LBL_FINANSIAL_SUMMARY', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'Accounts'],
				[6, 2823, 'accounts_available_taxes', 'vtiger_account', 1, 33, 'accounts_available_taxes', 'FL_AVAILABLE_TAXES', 0, 2, '', '65535', 3, 439, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->text(), 'blockLabel' => 'LBL_FOR_THE_PORTAL', 'picklistValues' => ['LBL_REGIONAL_TAX', 'LBL_GROUP_TAX'], 'relatedModules' => [], 'moduleName' => 'Accounts'],

				[96, 2789, 'featured', 'u_yf_knowledgebase', 1, 56, 'featured', 'FL_FEATURED', 0, 2, '', '-128,127', 6, 314, 1, 'C~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->tinyInteger(1), 'blockLabel' => 'LBL_KNOWLEDGEBASE_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'KnowledgeBase'],
				[96, 2790, 'introduction', 'u_yf_knowledgebase', 1, 300, 'introduction', 'FL_INTRODUCTION', 0, 2, '', '65535', 2, 315, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->text(), 'blockLabel' => 'LBL_ARTICLE', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'KnowledgeBase'],

				[111, 2801, 'category', 'u_yf_notification', 1, 302, 'category', 'FL_CATEGORY', 0, 2, '', '30', 7, 374, 1, 'V~O', 2, 3, 'BAS', 1, '', 0, '30', '', 0, 0, 0, 'type' => $importerType->stringType(30), 'blockLabel' => 'LBL_NOTIFICATION_INFORMATION', 'picklistValues' => [
					'column' => 'category',
					'base' => [30, 'Category', \App\Module::getModuleId('Notification'), 0],
					'data' => [[30, 'Base', 'T1', 'T1', 0, 'Base', '{"loaded":"1","opened":false,"selected":false,"disabled":false}', '']]
				], 'relatedModules' => [], 'moduleName' => 'Notification'],

				[90, 2792, 'istorageaddressid', 'u_yf_ssingleorders', 1, 10, 'istorageaddressid', 'FL_STORAGE', 0, 2, '', null, 15, 284, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->integer(10), 'blockLabel' => 'LBL_SSINGLEORDERS_INFORMATION', 'picklistValues' => [], 'relatedModules' => ['IStorages'], 'moduleName' => 'SSingleOrders'],
				[90, 2818, 'ssingleorders_method_payments', 'u_yf_ssingleorders', 1, 16, 'ssingleorders_method_payments', 'FL_METHOD_PAYMENTS', 0, 2, '', '255', 16, 284, 1, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->stringType(255), 'blockLabel' => 'LBL_SSINGLEORDERS_INFORMATION', 'picklistValues' => ['PLL_REDSYS', 'PLL_DOTPAY', 'PLL_TRANSFER', 'PLL_CASH_ON_DELIVERY'], 'relatedModules' => [], 'moduleName' => 'SSingleOrders'],
				[90, 2826, 'payment_status', 'u_yf_ssingleorders', 1, 15, 'payment_status', 'FL_PAYMENT_STATUS', 1, 2, 'PLL_NOT_PAID', '255', 17, 284, 10, 'V~O', 1, 0, 'BAS', 1, '', 0, '', null, 0, 0, 0, 'type' => $importerType->stringType(255), 'blockLabel' => 'LBL_SSINGLEORDERS_INFORMATION', 'picklistValues' => ['PLL_NOT_PAID', 'PLL_UNDERPAID', 'PLL_PAID', 'PLL_OVERPAID'], 'relatedModules' => [], 'moduleName' => 'SSingleOrders'],

				[13,2791,'parentid','vtiger_troubletickets',1,10,'parentid','FL_HELP_DESK_PARENT',0,0,'',null,13,27,1,'V~O',1,0,'BAS',1,'',0,'',null,0,0,0, 'type' => $importerType->integer(10), 'blockLabel' => 'LBL_CUSTOM_INFORMATION', 'picklistValues' => [], 'relatedModules' => [], 'moduleName' => 'HelpDesk'],
			];
		}

		foreach ($fields as $field) {
			$moduleId = App\Module::getModuleId($field['moduleName']);
			$isExists = (new \App\Db\Query())->from('vtiger_field')->where(['tablename' => $field[3], 'columnname' => $field[2], 'tabid' => $moduleId])->exists();
			if (!$moduleId || $isExists) {
				$this->log("[INFO] Skip adding field. Module: {$moduleId}-{$field['moduleName']}; field name: {$field[2]}, field exists: {$isExists}");
				continue;
			}
			$blockInstance = false;
			$blockId = (new \App\Db\Query())->select(['blockid'])->from('vtiger_blocks')->where(['blocklabel' => $field['blockLabel'], 'tabid' => $moduleId])->scalar();
			if ($blockId) {
				$blockInstance = \vtlib\Block::getInstance($blockId);
			}
			if (!$blockInstance && !($blockInstance = reset(Vtiger_Module_Model::getInstance($field['moduleName'])->getBlocks()))) {
				\App\Log::error("No block found ({$field['blockLabel']}) to create a field, you will need to create a field manually. Module: {$field['moduleName']}, field name: {$field[6]}, field label: {$field[7]}");
				$this->log("[ERROR] No block found to create a field, you will need to create a field manually. Module: {$field['moduleName']}, field name: {$field[6]}, field label: {$field[7]}");
				continue;
			}
			$fieldInstance = new \vtlib\Field();
			$fieldInstance->column = $field[2];
			$fieldInstance->name = $field[6];
			$fieldInstance->label = $field[7];
			$fieldInstance->table = $field[3];
			$fieldInstance->uitype = $field[5];
			$fieldInstance->typeofdata = $field[15];
			$fieldInstance->readonly = $field[8];
			$fieldInstance->displaytype = $field[14];
			$fieldInstance->masseditable = $field[19];
			$fieldInstance->quickcreate = $field[16];
			$fieldInstance->columntype = $field['type'];
			$fieldInstance->presence = $field[9];
			$fieldInstance->maximumlength = $field[11];
			$fieldInstance->quicksequence = $field[17];
			$fieldInstance->info_type = $field[18];
			$fieldInstance->helpinfo = $field[20];
			$fieldInstance->summaryfield = $field[21];
			$fieldInstance->generatedtype = $field[4];
			$fieldInstance->defaultvalue = $field[10];
			if ($field['picklistValues'] && 302 == $field[5]) {
				$field[22] = $this->setTree($field['picklistValues']);
			}
			$fieldInstance->fieldparams = $field[22];
			$blockInstance->addField($fieldInstance);
			if ($field['picklistValues'] && (15 == $field[5] || 16 == $field[5] || 33 == $field[5])) {
				$fieldInstance->setPicklistValues($field['picklistValues']);
			}
			if ($field['relatedModules'] && 10 == $field[5]) {
				$fieldInstance->setRelatedModules($field['relatedModules']); //setRelatedList
			}
		}
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function setTree($tree)
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$db = Db::getInstance();
		$dbCommand = $db->createCommand();
		$skipCheckData = false;
		$templateId = (new \App\Db\Query())->select(['templateid'])->from('vtiger_trees_templates')->where(['module' => $tree['base'][2]])->scalar();
		if (!$templateId) {
			$dbCommand->insert('vtiger_trees_templates', [
				'name' => $tree['base'][1],
				'module' => $tree['base'][2],
				'access' => $tree['base'][3]
			])->execute();
			$templateId = $db->getLastInsertID('vtiger_trees_templates_templateid_seq');
			// $dbCommand->update('vtiger_field', ['fieldparams' => $templateId], ['tabid'=>$tree['base'][2], 'columnname'=>$tree['column']])->execute();
			$skipCheckData = true;
		}
		foreach ($tree['data'] as $data) {
			if (!$skipCheckData && (new \App\Db\Query())->from('vtiger_trees_templates_data')->where(['templateid' => $templateId, 'name' => $data[1]])->exists()) {
				continue;
			}
			$dbCommand->insert('vtiger_trees_templates_data', [
				'templateid' => $templateId,
				'name' => $data[1],
				'tree' => $data[2],
				'parenttrre' => $data[3],
				'depth' => $data[4],
				'label' => $data[5],
				'state' => $data[6],
				'icon' => $data[7]
			])->execute();
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
		return $templateId;
	}

	/**
	 * Update fields.
	 */
	private function updateFields()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));
		$moduleModel = Vtiger_Module_Model::getInstance('Users');
		foreach ($moduleModel->getFieldsByUiType(16) as $fieldModel) {
			if (!$fieldModel->isMandatory()) {
				$fieldModel->updateTypeofDataFromMandatory('M')->save();
			}
		}
		$moduleByColumn = (new \App\Db\Query())->select(['vtiger_tab.name', 'vtiger_field.fieldname'])->from('vtiger_field')->innerJoin('vtiger_tab', '`vtiger_field`.`tabid` = vtiger_tab.`tabid`')->where(['columnname' => 'closedtime', 'tablename' => 'vtiger_crmentity'])->createCommand()->queryAllByGroup();

		$modules = ['HelpDesk' => ['response_time'], 'SQuoteEnquiries' => ['response_time'], 'SRequirementsCards' => ['response_time'], 'SCalculations' => ['response_time'], 'SQuotes' => ['response_time'], 'SSingleOrders' => ['response_time'], 'SRecurringOrders' => ['response_time'], 'SVendorEnquiries' => ['response_time'], 'Accounts' => ['active'], 'Contacts' => ['active'], 'OSSMailView' => ['rel_mod']];
		$modules = array_merge_recursive($modules, $moduleByColumn);
		foreach ($modules as $moduleName => $fields) {
			$moduleModel = Vtiger_Module_Model::getInstance($moduleName);
			if (!\is_array($fields)) {
				$fields = [$fields];
			}
			foreach ($fields as $fieldName) {
				if ($fieldModel = $moduleModel->getFieldByName($fieldName)) {
					if (\in_array($fieldName, ['active', 'rel_mod']) || !$fieldModel->isActiveField() || !$this->isExistsValueForField($moduleName, $fieldName)) {
						$this->removeField($fieldModel);
					} else {
						$this->log('RemoveFields' . __METHOD__ . ': field exists and is in use ' . $fieldModel->getName() . ' ' . $fieldModel->getModuleName());
					}
				}
			}
		}

		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function removeField($fieldModel, $newName = false)
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));
		try {
			if (false === $newName) {
				$fieldInstance = Settings_LayoutEditor_Field_Model::getInstance($fieldModel->getId());
				$fieldInstance->delete();
			} else {
				$db = \App\Db::getInstance();
				$dbCommand = $db->createCommand();

				$fldModule = $fieldModel->getModuleName();
				$id = $fieldModel->getId();
				$fieldname = $fieldModel->getName();
				$tabId = $fieldModel->getModuleId();

				$dbCommand->delete('a_#__mapped_fields', ['or', ['source' => $id], ['target' => $id]])->execute();
				$dbCommand->update('vtiger_cvcolumnlist', ['field_name' => $newName], ['field_name' => $fieldname, 'module_name' => $fldModule])->execute();
				$dbCommand->delete('u_#__cv_condition', ['field_name' => $fieldname, 'module_name' => $fldModule])->execute();

				if ('picklist' === $fieldModel->getFieldDataType() || 'multipicklist' === $fieldModel->getFieldDataType()) {
					$query = (new \App\Db\Query())->from('vtiger_field')
						->where(['fieldname' => $fieldname])
						->andWhere(['in', 'uitype', [15, 16, 33]]);
					$dataReader = $query->createCommand()->query();
					if (!$dataReader->count()) {
						$dbCommand->dropTable('vtiger_' . $fieldname)->execute();
						if ($db->isTableExists('vtiger_' . $fieldname . '_seq')) {
							$dbCommand->dropTable('vtiger_' . $fieldname . '_seq')->execute();
						}
						$dbCommand->delete('vtiger_picklist', ['name' => $fieldname])->execute();
					}
					$dbCommand->delete('vtiger_picklist_dependency', ['and', ['tabid' => $tabId], ['or', ['sourcefield' => $fieldname], ['targetfield' => $fieldname]]])->execute();
				}
			}
		} catch (\Throwable $e) {
			$message = 'RemoveFields' . __METHOD__ . ': ' . $e->__toString();
			$this->log($message);
			\App\Log::error($message);
		}
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	private function isExistsValueForField($moduleName, $fieldName)
	{
		$queryGenerator = new \App\QueryGenerator($moduleName);
		$queryGenerator->permission = false;
		$queryGenerator->setStateCondition('All');
		$queryGenerator->addNativeCondition(['<>', 'vtiger_crmentity.deleted', [0]]);
		$queryGenerator->addCondition($fieldName, '', 'ny');
		return $queryGenerator->createQuery()->exists();
	}

	private function setRelations()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));
		$dbCommand = \App\Db::getInstance()->createCommand();

		$moduleModel = Vtiger_Module_Model::getInstance('HelpDesk');
		if ($fieldModel = $moduleModel->getFieldByName('pssold_id')) {
			$modules = ['OutsourcedProducts', 'OSSOutsourcedServices'];
			$fieldModel->setRelatedModules($modules);
			foreach ($modules as $module) {
				Vtiger_Module_Model::getInstance($module)->setRelatedList($moduleModel, $moduleModel->getName(), 'ADD', 'getDependentsList');
			}
		}
		$moduleModel = Vtiger_Module_Model::getInstance('Faq');
		$moduleModel->setRelatedList($moduleModel, 'LBL_RELATED_FAQ', 'SELECT', 'getManyToMany');

		$moduleModel = Vtiger_Module_Model::getInstance('KnowledgeBase');
		$moduleModel->setRelatedList($moduleModel, 'LBL_RELATED_KNOWLEDGE_BASES', 'SELECT', 'getManyToMany');

		$ralations = [
			['type' => 'add', 'data' => [599, 'SSalesProcesses', 'FInvoice', 'getDependentsList', 24, 'FInvoice', 0, 'ADD', 0, 0, 0, 'RelatedTab']],
			['type' => 'add', 'data' => [600, 'Project', 'FInvoice', 'getDependentsList', 13, 'FInvoice', 0, 'ADD', 0, 0, 0, 'RelatedTab']],
			['type' => 'add', 'data' => [601, 'ServiceContracts', 'Assets', 'getRelatedList', 8, 'Assets', 0, 'SELECT', 0, 0, 0, 'RelatedTab']],
			['type' => 'add', 'data' => [602, 'ServiceContracts', 'OSSSoldServices', 'getRelatedList', 9, 'OSSSoldServices', 0, 'SELECT', 0, 0, 0, 'RelatedTab']],
			['type' => 'add', 'data' => [603, 'Assets', 'ServiceContracts', 'getRelatedList', 3, 'ServiceContracts', 0, 'SELECT', 0, 0, 0, 'RelatedTab']],
			['type' => 'add', 'data' => [604, 'OSSSoldServices', 'ServiceContracts', 'getRelatedList', 3, 'ServiceContracts', 0, 'SELECT', 0, 0, 0, 'RelatedTab']],
			['type' => 'update', 'data' => [605, 'KnowledgeBase', 'KnowledgeBase', 'getManyToMany', 1, 'LBL_RELATED_KNOWLEDGE_BASES', 0, 'SELECT', 0, 0, 0, 'RelatedTab,DetailBottom']],
			// ['type' => 'add', 'data' => [606, 'OutsourcedProducts', 'HelpDesk', 'getDependentsList', 2, 'HelpDesk', 0, 'ADD', 0, 0, 0, 'RelatedTab']],
			// ['type' => 'add', 'data' => [607, 'OSSOutsourcedServices', 'HelpDesk', 'getDependentsList', 2, 'HelpDesk', 0, 'ADD', 0, 0, 0, 'RelatedTab']],
		];
		foreach ($ralations as $relation) {
			[, $moduleName, $relModuleName, $name, $sequence, $label, $presence, $actions, $favorites, $creatorDetail, $relationComment, $viewType] = $relation['data'];
			$tabid = \vtlib\Functions::getModuleId($moduleName);
			$relTabid = \vtlib\Functions::getModuleId($relModuleName);
			$isExists = (new \App\Db\Query())->from('vtiger_relatedlists')->where(['tabid' => $tabid, 'related_tabid' => $relTabid, 'name' => $name])->exists();
			if (!$isExists && 'add' === $relation['type']) {
				$dbCommand->insert('vtiger_relatedlists', [
					'tabid' => $tabid,
					'related_tabid' => $relTabid,
					'name' => $name,
					'sequence' => $sequence,
					'label' => $label,
					'presence' => $presence,
					'actions' => $actions,
					'favorites' => $favorites,
					'creator_detail' => $creatorDetail,
					'relation_comment' => $relationComment,
					'view_type' => $viewType
				])->execute();
			} elseif ($isExists && 'update' === $relation['type']) {
				$dbCommand->update('vtiger_relatedlists', [
					'name' => $name,
					'sequence' => $sequence,
					'label' => $label,
					'presence' => $presence,
					'actions' => $actions,
					'favorites' => $favorites,
					'creator_detail' => $creatorDetail,
					'relation_comment' => $relationComment,
					'view_type' => $viewType
				], ['tabid' => $tabid, 'related_tabid' => $relTabid, 'name' => $name])->execute();
			}
		}
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	/**
	 * Set action mapping.
	 */
	private function actionMapp()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));
		$inventoryModules = (new \App\Db\Query())->select(['tabid'])->from('vtiger_tab')->where(['type' => 1])->column();
		$actions = [
			['type' => 'add', 'name' => 'RecordPdfInventory', 'tabsData' => $inventoryModules]
		];
		$db = \App\Db::getInstance();
		foreach ($actions as $action) {
			$key = (new \App\Db\Query())->select(['actionid'])->from('vtiger_actionmapping')->where(['actionname' => $action['name']])->limit(1)->scalar();
			if ('remove' === $action['type']) {
				if ($key) {
					$db->createCommand()->delete('vtiger_actionmapping', ['actionid' => $key])->execute();
					$db->createCommand()->delete('vtiger_profile2utility', ['activityid' => $key])->execute();
				}
				continue;
			}
			if (empty($key)) {
				$securitycheck = 0;
				$key = $db->getUniqueID('vtiger_actionmapping', 'actionid', false);
				$db->createCommand()->insert('vtiger_actionmapping', ['actionid' => $key, 'actionname' => $action['name'], 'securitycheck' => $securitycheck])->execute();
			}
			$permission = 1;
			if (isset($action['permission'])) {
				$permission = $action['permission'];
			}

			$tabsData = $action['tabsData'];
			$dataReader = (new \App\Db\Query())->select(['profileid'])->from('vtiger_profile')->createCommand()->query();
			while (false !== ($profileId = $dataReader->readColumn(0))) {
				foreach ($tabsData as $tabId) {
					$isExists = (new \App\Db\Query())->from('vtiger_profile2utility')->where(['profileid' => $profileId, 'tabid' => $tabId, 'activityid' => $key])->exists();
					if (!$isExists) {
						$db->createCommand()->insert('vtiger_profile2utility', [
							'profileid' => $profileId, 'tabid' => $tabId, 'activityid' => $key, 'permission' => $permission
						])->execute();
					}
				}
			}
		}
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
	}

	/**
	 * Postupdate.
	 */
	public function postupdate()
	{
		$start = microtime(true);
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s'));

		$menuRecordModel = new \Settings_Menu_Record_Model();
		$menuRecordModel->refreshMenuFiles();
		$this->createConfigFiles();
		$this->log(__METHOD__ . '| ' . date('Y-m-d H:i:s') . ' | ' . round((microtime(true) - $start) / 60, 2) . ' mim.');
		$this->stopProcess();
		return true;
	}

	public function stopProcess()
	{
		\App\Module::createModuleMetaFile();
		\App\Cache::clear();
		\App\Cache::clearOpcache();
		try {
			$dbCommand = \App\Db::getInstance()->createCommand();
			$dbCommand->insert('yetiforce_updates', [
				'user' => \Users_Record_Model::getCurrentUserModel()->get('user_name'),
				'name' => (string) $this->modulenode->label,
				'from_version' => (string) $this->modulenode->from_version,
				'to_version' => (string) $this->modulenode->to_version,
				'result' => true,
				'time' => date('Y-m-d H:i:s')
			])->execute();
			$dbCommand->update('vtiger_version', ['current_version' => (string) $this->modulenode->to_version])->execute();
			\vtlib\Functions::recurseDelete('cache/updates');
			\vtlib\Functions::recurseDelete('cache/templates_c');

			\App\Cache::clear();
			\App\Cache::clearOpcache();
			clearstatcache();
		} catch (\Throwable $ex) {
			file_put_contents('cache/logs/update.log', $ex->__toString(), FILE_APPEND);
		}
		file_put_contents('cache/logs/update.log', ob_get_contents(), FILE_APPEND);
		ob_end_clean();
		echo '<div class="modal in" style="display: block;top: 20%;"><div class="modal-dialog"><div class="modal-content"><div class="modal-header">
		<h4 class="modal-title">' . \App\Language::translate('LBL__UPDATING_MODULE', 'Settings:ModuleManager') . '</h4>
		</div><div class="modal-body">' . \App\Language::translate('LBL_IMPORTED_UPDATE', 'Settings:ModuleManager') .
			'</div><div class="modal-footer"><a class="btn btn-success" href="index.php?module=LangManagement&parent=Settings&view=Index&block=4&fieldid=53"></span>' . \App\Language::translate('LangManagement', 'Settings:LangManagement') . '<a></div></div></div></div>';
		exit;
	}

	private function createConfigFiles()
	{
		\App\Config::set('module', 'OSSMail', 'root_directory', new \Nette\PhpGenerator\PhpLiteral('ROOT_DIRECTORY . DIRECTORY_SEPARATOR'));
		// \App\Config::set('module', 'Project', 'defaultGanttColors', [
		// 	'Project' => [
		// 		'projectstatus' => [
		// 			'PLL_PLANNED' => '#7B1FA2',
		// 			'PLL_IN_PROGRESSING' => '#1976D2',
		// 			'PLL_IN_APPROVAL' => '#F57C00',
		// 			'PLL_ON_HOLD' => '#455A64',
		// 			'PLL_COMPLETED' => '#388E3C',
		// 			'PLL_CANCELLED' => '#616161',
		// 		],
		// 	],
		// 	'ProjectMilestone' => [
		// 		'projectmilestone_status' => [
		// 			'PLL_PLANNED' => '#3F51B5',
		// 			'PLL_IN_PROGRESSING' => '#2196F3',
		// 			'PLL_COMPLETED' => '#4CAF50',
		// 			'PLL_ON_HOLD' => '#607D8B',
		// 			'PLL_CANCELLED' => '#9E9E9E',
		// 		],
		// 	],
		// 	'ProjectTask' => [
		// 		'projecttaskstatus' => [
		// 			'PLL_PLANNED' => '#7986CB',
		// 			'PLL_IN_PROGRESSING' => '#64B5F6',
		// 			'PLL_COMPLETED' => '#81C784',
		// 			'PLL_ON_HOLD' => '#90A4AE',
		// 			'PLL_CANCELLED' => '#E0E0E0',
		// 		],
		// 	]
		// ]);
		$skip = ['module', 'component'];
		foreach (array_diff(\App\ConfigFile::TYPES, $skip) as $type) {
			(new \App\ConfigFile($type))->create();
		}
		$dirPath = \ROOT_DIRECTORY . \DIRECTORY_SEPARATOR . 'config' . \DIRECTORY_SEPARATOR . 'Modules';
		if (!is_dir($dirPath)) {
			mkdir($dirPath);
		}
		foreach ((new \DirectoryIterator('modules/')) as $item) {
			if ($item->isDir() && !$item->isDot()) {
				$moduleName = $item->getBasename();
				$filePath = 'modules' . \DIRECTORY_SEPARATOR . $moduleName . \DIRECTORY_SEPARATOR . 'ConfigTemplate.php';
				if (file_exists($filePath)) {
					(new \App\ConfigFile('module', $moduleName))->create();
				}
			}
		}
		$path = \ROOT_DIRECTORY . \DIRECTORY_SEPARATOR . 'config' . \DIRECTORY_SEPARATOR . 'Components' . \DIRECTORY_SEPARATOR . 'ConfigTemplates.php';
		$componentsData = require_once "$path";
		foreach ($componentsData as $component => $data) {
			(new \App\ConfigFile('component', $component))->create();
		}
	}
}
