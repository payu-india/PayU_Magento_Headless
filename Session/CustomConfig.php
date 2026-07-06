<?php

namespace PayUIndia\Payu\Session;

use Magento\Framework\Session\Config as DefaultConfig;

class CustomConfig extends DefaultConfig
{
    public function setCookiePath($path, $default = null)
    {   
        parent::setCookiePath($path, $default);
		/*
		$options = session_get_cookie_params();  
		$options['samesite'] = 'None';
		$options['secure'] = true;
		unset($options['lifetime']); 
		$cookies = $_COOKIE;  	
		foreach ($cookies as $key => $value)
		{
			if (!preg_match('/cart/', $key))
				setcookie($key, $value, $options);
		}
		*/
        return $this;
    }
}
