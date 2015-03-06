<?php  if ( ! defined('BASEPATH')) exit('No direct script access allowed');
class Apiaccess
{
	private $_origin;
	private $_CI;
	private $Table = 'apiaccess';
	public function __construct()
	{
		$this->_CI =& get_instance();
		$this->_CI->load->database();
		if(isset($_SERVER['HTTP_REFERER']))
			$this->_origin = parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST);
		else
			$this->_origin = $_SERVER['REMOTE_ADDR'];
	}

	public function check($kill_on_disallowed = false)
	{
		if($this->_CI->db->get_where($this->Table, array('origin' => $this->_origin))->num_rows() > 0)
			return true;

		if($kill_on_disallowed)
		{
			header('HTTP/1.0 403 Forbidden');
			die('This host (' . htmlentities($this->_origin) . ') is not allowed to access the api.');
		}

		return false;
	}
}