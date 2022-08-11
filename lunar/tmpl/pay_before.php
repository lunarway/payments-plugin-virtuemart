
<?php
defined ('_JEXEC') or die();

/**
 * @package VirtueMart
 * @subpackage payment
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

$method = $viewData["method"];
include_once(JPATH_PLUGINS.'/'.$this->_type . '/' . $this->_name.'/tmpl/pay_before_js.php');
 ?>
<script>
	vmLunar.method["ID<?php echo $method->virtuemart_paymentmethod_id ?>"] = <?php echo $method->virtuemart_paymentmethod_id; ?>;
	vmLunar.popup_title = <?php echo json_encode($method->popup_title) ?>;
</script>
