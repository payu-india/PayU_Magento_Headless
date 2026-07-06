<?php
namespace PayUIndia\Payu\Plugin;

class CsrfValidatorSkip
{
    /**
     * CsrfValidator to skip validation 
     *
     * @param \Magento\Framework\App\Request\CsrfValidator $subject
     * @param \Closure $proceed
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\App\ActionInterface $action
     */
    public function aroundValidate(
        $subject,
        \Closure $proceed,
        $request,
        $action
    ) {
		//$writer = new \Zend_Log_Writer_Stream(BP . '/var/log/payu.log');
		//$logger = new \Zend_Log();
		//$logger->addWriter($writer);
		
        if ($request->getModuleName() == 'payu') {
			//$logger->info("Module Name ".$request->getModuleName());
            return; // Skip CSRF check
        }
        $proceed($request, $action); // Proceed Magento 2 core functionalities
    }
}
