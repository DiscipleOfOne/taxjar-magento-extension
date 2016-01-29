<?php
/**
 * TaxJar Observer.
 *
 * @author Taxjar (support@taxjar.com)
 */
class Taxjar_SalesTax_Model_Observer
{
  /**
   * TaxJar observer
   *
   * @param Varien_Event_Observer $observer
   * @return void
   */
  public function execute($observer)
  {
    $session = Mage::getSingleton('adminhtml/session');
    $apiKey = Mage::getStoreConfig('taxjar/config/apikey');
    $apiKey = preg_replace('/\s+/', '', $apiKey);

    if ($apiKey) {
      $this->version     = 'v2';
      $client            = Mage::getModel('taxjar/client');
      $configuration     = Mage::getModel('taxjar/configuration');
      $regionId          = Mage::getStoreConfig('shipping/origin/region_id');
      $this->storeZip    = Mage::getStoreConfig('shipping/origin/postcode');
      $this->regionCode  = Mage::getModel('directory/region')->load($regionId)->getCode();
      $validZip          = preg_match("/(\d{5}-\d{4})|(\d{5})/", $this->storeZip);
      $debug             = Mage::getStoreConfig('taxjar/config/debug');

      if (isset($this->regionCode)) {
        $configJson = $client->getResource( $apiKey, $this->apiUrl( 'config' ) );
        $configJson = $configJson['configuration'];
      } else {
        Mage::throwException("Please check that you have set a Region/State in Shipping Settings.");
      }

      if ($debug) {
        Mage::getSingleton('core/session')->addNotice("Debug mode enabled. Tax rates have not been altered.");
        return;
      }

      if ($configJson['wait_for_rates'] > 0) {
        $dateUpdated = Mage::getStoreConfig('taxjar/config/last_update');
        Mage::getSingleton('core/session')->addNotice("Your last rate update was too recent. Please wait at least 5 minutes and try again.");
        return;
      }

      if ($validZip === 1 && isset($this->storeZip) && trim($this->storeZip) !== '' ) {
        $ratesJson = $client->getResource($apiKey, $this->apiUrl('rates'));
      } else {
        Mage::throwException("Please check that your zip code is a valid US zip code in Shipping Settings.");
      }

      Mage::getModel('core/config')
        ->saveConfig('taxjar/config/states', serialize(explode( ',', $configJson['states'])));
      $configuration->setTaxBasis($configJson);
      $configuration->setShippingTaxability($configJson);
      $configuration->setDisplaySettings();
      $configuration->setApiSettings($apiKey);
      Mage::getModel('core/config')
        ->saveConfig('taxjar/config/freight_taxable', $configJson['freight_taxable']);
      $this->purgeExisting();

      if (false !== file_put_contents($this->getTempFileName(), serialize($ratesJson))) {
        Mage::dispatchEvent('taxjar_salestax_import_rates'); 
      } else {
        // We need to be able to store the file...
        Mage::throwException("Could not write to your Magento temp directory. Please check permissions for " . Mage::getBaseDir('tmp') . ".");
      }
    } else {
      Mage::getSingleton('core/session')->addNotice("TaxJar has been uninstalled. All tax rates have been removed.");
      $this->purgeExisting();
      $this->setLastUpdateDate(NULL);
    }
    // Clearing the cache to avoid UI elements not loading
    Mage::app()->getCacheInstance()->flush();
  }

  /**
   * Read our file and import all the rates, triggered via taxjar_salestax_import_rates
   *
   * @param void
   * @return void
   */
  public function importRates()
  {
    // This process can take a while
    @set_time_limit(0);
    @ignore_user_abort(true);

    $this->newRates            = array();
    $this->freightTaxableRates = array();
    $rate                      = Mage::getModel('taxjar/rate');
    $filename                  = $this->getTempFileName();
    $rule                      = Mage::getModel('taxjar/rule');
    $shippingTaxable           = Mage::getStoreConfig('taxjar/config/freight_taxable');
    $ratesJson                 = unserialize(file_get_contents($filename));

    foreach ($ratesJson['rates'] as $rateJson) {
      $rateIdWithShippingId = $rate->create( $rateJson );
      
      if ($rateIdWithShippingId[0]) {
        $this->newRates[] = $rateIdWithShippingId[0];
      }

      if ($rateIdWithShippingId[1]) {
        $this->freightTaxableRates[] = $rateIdWithShippingId[1];
      }
    }

    $this->setLastUpdateDate(date('m-d-Y'));
    $rule->create('Retail Customer-Taxable Goods-Rate 1', 2, 1, $this->newRates);

    if ($shippingTaxable) {
      $rule->create('Retail Customer-Shipping-Rate 1', 4, 2, $this->freightTaxableRates); 
    }

    @unlink($filename);
    Mage::getSingleton('core/session')->addSuccess('TaxJar has added new rates to your database! Thanks for using TaxJar!');
    Mage::dispatchEvent('taxjar_salestax_import_rates_after');
  }

  /**
   * Build URL string
   *
   * @param $string
   * @return $string
   */
  private function apiUrl($type)
  {
    $apiHost = 'https://api.taxjar.com/';
    $prefix  = $apiHost . $this->version . '/plugins/magento/';

    if ($type == 'config') {
      return $prefix . 'configuration/' . $this->regionCode;
    } elseif ($type == 'rates') {
      return $prefix . 'rates/' . $this->regionCode . '/' . $this->storeZip;
    }
  }

  /**
   * Purges the rates and rules
   *
   * @param void
   * @return void
   */
  private function purgeExisting()
  {
    $rates = Mage::getModel('taxjar/rate')->getExistingRates()->load();
    
    foreach ($rates as $rate) {
      try {
        $calculation = Mage::getModel('tax/calculation')->load($rate->getId(), 'tax_calculation_rate_id');
        $calculation->delete();
      } catch (Exception $e) {
        Mage::getSingleton('core/session')->addError("There was an error deleting from Magento model tax/calculation");
      }
      
      try {
        $rate->delete();
      } catch (Exception $e) {
        Mage::getSingleton('core/session')->addError("There was an error deleting from Magento model tax/calculation_rate");
      }
    }
  }

  /**
   * Set the last updated date
   *
   * @param $string || NULL
   * @return void
   */
  private function setLastUpdateDate($date)
  {
    Mage::getModel('core/config')->saveConfig('taxjar/config/last_update', $date);
  }

  /**
   * Set the filename
   *
   * @param void
   * @return $string
   */
  private function getTempFileName()
  {
    return Mage::getBaseDir('tmp') . DS . "tj_tmp.dat";
  }
}
