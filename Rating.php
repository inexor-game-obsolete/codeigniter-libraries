<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

class Rating {

	private $_module = '';
	private $_CI;
	private $_user;
	private $_just_rated; 
	private $_configfile;

	/**
	 * Magic Method __construct();
	 * Constructor
	 */
	public function __construct()
	{
		$this->_CI =& get_instance();
		$this->_configfile = APPPATH . 'config/rating_system.php';
		$this->_CI->load->model("shared/rating_model");
		$this->_CI->load->library("auth");
		$this->_CI->load->database();
		$this->_user = $this->_CI->auth->user();
		$this->_just_rated = $this->_check();
	}

    /**
	 * Magic Method __call(); Pass-trough to the rating_model
	 * @param string $method Method in the rating_model
	 * @param array $arguments The arguments to pass trough
	 * @return mixed The return of $method-function
	 */
	public function __call($method, $arguments)
	{
		if (!method_exists( $this->_CI->rating_model, $method) )
		{
			throw new Exception('Undefined method Comments::' . $method . '() called');
		}

		return call_user_func_array(array($this->_CI->rating_model, $method), $arguments);
	}

	/**
	 * Returns the previous return from _check()
	 * Getter for $this->_just_rated
	 * @return mixed false if not submitted(it's the users own post or not enough info)
	 *                     0 if previous rating was removed
	 *                     1 if rated positive
	 *                     -1 if rated negative
	 */
	public function check()
	{
		return $this->_just_rated;
	}

	public function user_rating($module, $identifier)
	{
		if(!$module)
			$module = $this->_module;
		if(!isset($this->_user->id))
			return 0;
		return $this->_CI->rating_model->user_rating($this->_user->id, $module, $identifier);
	}

	/**
	 * Returns an object of votings positive and negative
	 * @param  string  $module       the module-name
	 * @param  string  $identifier   the identifier-name
	 * @param  boolean $use_stdClass use stdClass and only return variables, no display-methods
	 * @return object                stdclass containing ->positive, ->negative, ->ratings
	 */
	public function get_ratings($module, $identifier, $use_stdClass = false)
	{
		if($module == false)
			$module = $this->_module;

		if(is_array($identifier))
		{
			$return = array();
			foreach($identifier as $v)
			{
				array_push($return, $this->get_ratings($module, $v, $use_stdClass));
			}
			return $return;
		}

		$this->_CI->load->library("template");
		$template =& $this->_CI->template;

		if($use_stdClass)
			$return = new stdCLass();
		else
			$return = new rate();
		
		$logged_in = isset($this->_user->id);
		$return->positive   = $this->_CI->rating_model->get_positive($module, $identifier);
		$return->negative   = $this->_CI->rating_model->get_negative($module, $identifier);
		$return->ratings    = $return->positive + $return->negative;
		$return->user_vote  = $this->user_rating($module, $identifier);
		$return->module     = $module;
		$return->identifier = $identifier;
		$return->own_post   = $logged_in ? $this->own_post($this->_user->id, $module, $identifier) : false;

		if(!$use_stdClass)
		{
			$own_post = $return->own_post;

			$return->set_display('small', function ($data) use ($template, $logged_in, $own_post) {
				$data['logged_in'] = $logged_in;
				$data['own_post']  = $own_post;
				return $template->render_block(
					'rating/small', 
					$data,
					true,
					true
				);
			});

			$return->set_display('medium', function ($data) use ($template, $logged_in, $own_post) {
				$data['logged_in'] = $logged_in;
				$data['own_post']  = $own_post;
				return $template->render_block(
					'rating/medium', 
					$data,
					true,
					true
				);
			});

			$return->set_display('large', function ($data) use ($template, $logged_in, $own_post) {
				$data['logged_in'] = $logged_in;
				$data['own_post']  = $own_post;
				return $template->render_block(
					'rating/large', 
					$data,
					true,
					true
				);
			});
		}

		return $return;
	}

	/**
	 * Rates.
	 * @param  int     $rating     1 for positive, -1 for negative, 0 for none (previous ratings will be deleted)
	 * @param  string  $identifier identifier-name
	 * @param  string  $module     module-name
	 * @return boolean             false if not logged in
	 */
	public function rate($rating, $module, $identifier)
	{
		if($module == false)
			$module = $this->_module;

		if(!isset($this->_user->id))
			return false;

		$this->_CI->rating_model->rate($this->_user->id, $rating, $module, $identifier);
		return true;
	}

	/**
	 * Checks via the config file if the post associated to $module and $identifer
	 * was submitted by $userid
	 * @param  int     $userid     id of the user to check if he is the author
	 * @param  string  $module     the module-name of the module to rate for
	 * @param  string  $identifier the identifier-name of the module to rate for
	 * @return boolean             true if it is the own post, false if not
	 */
	public function own_post($userid, $module, $identifier)
	{
		$config = $this->_configfile;

		// Callback to prevent full access to all variables
		// for the included file.
		$get_config = function () use ($userid, $identifier, $config) {
			include($config);
			return $config;
		};

		$config = call_user_func($get_config);
		if(!isset($config[$module]) || !isset($config[$module]['table']) || !isset($config[$module]['condition']))
			return false;

		if($this->_CI->db->get_where($config[$module]['table'], $config[$module]['condition'])->num_rows() == 0)
			return false;

		return true;
	}

	/**
	 * Checks for a rate and submits it to the db.
	 * 
	 * @return mixed false if not submitted(it's the users own post or not enough info)
	 *                     0 if previous rating was removed
	 *                     1 if rated positive
	 *                     -1 if rated negative
	 */
	private function _check()
	{
		if(isset($_POST['rating']))
		{
			if(!isset($this->_user->id))
			{
				unset($_POST['rating']);
				return false;
			}
			$userid = $this->_user->id;

			if(isset($_POST['rate_module']) && strlen($_POST['rate_module']) > 0)
				$module = $_POST['rate_module'];
			else 
				$module = false;

			if(isset($_POST['rate_identifier']) && strlen($_POST['rate_identifier']) > 0)
				$identifier = $_POST['rate_identifier'];
			else
				$identifier = false;

			if($_POST['rating'] == 'up')
				$rating = 1;
			elseif($_POST['rating'] == 'down')
				$rating = -1;
			else
				$rating = 0;
			
			unset($_POST['rating']);

			if($module === false || $identifier === false)
				return false;

			if($this->own_post($userid, $module, $identifier))
				return false;

			$this->_CI->rating_model->rate($this->_user->id, $rating, $module, $identifier);

			return $rating;
		}
		return null;
	}
}

class rate {
	public $positive;
	public $negative;
	public $ratings;
	public $module;
	public $identifier;
	public $user_vote;
	private $_display = array();
	public function set_display($functionname, $function)
	{
		if(is_callable($function) && strlen($functionname) > 0)
		{
			$this->_display[$functionname] = $function;
		}
	}

	public function display($functionname, $anchor = false)
	{
		if(is_string($anchor) || is_numeric($anchor))
		{
			if(is_numeric($anchor) || $anchor[0] != '#')
				$anchor = '#' . $anchor;
		}
		else
			$anchor = false;

		return call_user_func_array(
			$this->_display[$functionname],
			array(
				array(
					'positive'   => $this->positive, 
					'negative'   => $this->negative, 
					'rating'     => $this->positive-$this->negative,
					'ratings'    => $this->ratings, 
					'module'     => $this->module, 
					'identifier' => $this->identifier, 
					'user_vote'  => $this->user_vote,
					'anchor'     => $anchor
				)
			)
		);
	}

	public function display_small($anchor = false)
	{
		return $this->display('small', $anchor);
	}

	public function display_medium($anchor = false)
	{
		return $this->display('medium', $anchor);
	}

	public function display_large($anchor = false)
	{
		return $this->display('large', $anchor);
	}
}