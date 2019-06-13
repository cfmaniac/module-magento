<?php

class Profileolabs_Shoppingflux_Model_Manageorders_Convert_Customer extends Varien_Object
{
    /**
     * @param array $data
     * @param int $storeId
     * @param Mage_Customer_Model_Customer|null $customer
     * @return Mage_Customer_Model_Customer
     */
    public function toCustomer(array $data, $storeId, $customer = null)
    {
        $websiteId = Mage::app()->getStore($storeId)->getWebsiteId();

        if (!$customer instanceof Mage_Customer_Model_Customer) {
            /** @var Mage_Customer_Model_Customer $customer */
            $customer = Mage::getModel('customer/customer');
            $customer->setWebsiteId($websiteId);
            $customer->loadByEmail($data['Email']);
            $customer->setImportMode(true);

            if (!$customer->getId()) {
                $customer->addData(
                    array(
                        'website_id' => $websiteId,
                        'confirmation' => null,
                        'force_confirmed' => true,
                        'password_hash' => $customer->hashPassword($customer->generatePassword(8)),
                        'from_shoppingflux' => 1,
                    )
                );
            }
        }

        /** @var Mage_Core_Helper_Data $coreHelper */
        $coreHelper = Mage::helper('core');
        $coreHelper->copyFieldset('shoppingflux_convert_customer', 'to_customer', $data, $customer);

        /* Modified to Split Customer Name into First and Last Name : JCH 05/2019*/
        if (trim($customer->getFirstname()) === '') {
            //$customer->setFirstname('__');
            $full = $customer->getLastName();
            $full1 = explode(' ', $full);
            $first = $full1[0];
            $last = ltrim($full, $first . ' ');
            $customer->setFirstname('__' & $first);
            $customer->setLastname($last);
        }

        return $customer;
    }

    /**
     * @param array $data
     * @param int $storeId
     * @param Mage_Customer_Model_Customer|null $customer
     * @param string $type
     * @return Mage_Customer_Model_Address
     */
    public function addresstoCustomer(array $data, $storeId, $customer = null, $type = 'billing')
    {
        if (!$customer instanceof Mage_Customer_Model_Customer) {
            /** @var Mage_Customer_Model_Customer $customer */
            $customer = $this->toCustomer($data, $storeId);
        }

        /** @var Mage_Customer_Model_Address $address */
        $address = Mage::getModel('customer/address');
        $address->setId(null);
        $address->setIsDefaultBilling(true);
        $address->setIsDefaultShipping(false);

        if ($type === 'shipping') {
            $address->setIsDefaultBilling(false);
            $address->setIsDefaultShipping(true);
        }

        /** @var Profileolabs_Shoppingflux_Helper_Data $helper */
        $helper = Mage::helper('profileolabs_shoppingflux');
        /** @var Mage_Core_Helper_Data $coreHelper */
        $coreHelper = Mage::helper('core');
        /** @var Mage_Core_Helper_String $stringHelper */
        $stringHelper = Mage::helper('core/string');

        $coreHelper->copyFieldset('shoppingflux_convert_customer', 'to_customer_address', $data, $address);

        if (trim($address->getFirstname()) === '') {
           /* Modified to Split Customer Name into First and Last Name : JCH 05/2019*/
           //$address->setFirstname(' __ ');
           $full = $address->getLastName();
		   $full1 = explode(' ', $full);
		   $first = $full1[0];
		   $last = ltrim($full, $first . ' ');
		   $address->setFirstname('__' & $first);
		   $address->setLastname($last);  
        }

        if (strpos(strtolower($address->getCountryId()), 'france') !== false) {
            $address->setCountryId('FR');
        }

        if ((trim($address->getTelephone()) === '') && $data['PhoneMobile']) {
            $address->setTelephone($data['PhoneMobile']);
        }

        /** @var Profileolabs_Shoppingflux_Model_Config $config */
        $config = Mage::getSingleton('profileolabs_shoppingflux/config');

        if ($data['PhoneMobile'] && $stringHelper->strlen(trim($data['PhoneMobile'])) >= 9) {
            if ($mobilePhoneAttribute = $config->getMobilePhoneAttribute($storeId)) {
                $customer->setData($mobilePhoneAttribute, $data['PhoneMobile']);
            } elseif ($config->preferMobilePhone($storeId)) {
                $address->setTelephone($data['PhoneMobile']);
            }
        }

        $regionId = false;
        $regionCode = false;
        $isAddressRegionCode = false;
        $countryId = strtoupper($address->getCountryId());

        if ($countryId === 'FR') {
            $regionCode = $stringHelper->substr(str_pad($address->getPostcode(), 5, '0', STR_PAD_LEFT), 0, 2);
        } elseif (in_array($countryId, array('CA', 'US'), true)) {
           //Modified to set state/region for US addresses
           $didSetUSState = FALSE;
           if ('US' == $data['Country']) {
            // set state for US address
            // (the default behavior was to put the State in Street 2, which doesn't work for us)
            $state  = strtoupper(trim($data['Street2']));
            $region = Mage::getModel('directory/region')->loadByCode($state, 'US');
            if (is_object($region)) {           
                $address->setStreet( array($data['Street1'], '') );
                $address->setRegion( $region->getName() );
                $address->setRegionId( $region->getId() );            
                $didSetUSState = TRUE;
                Mage::log('US State: ' . $region->getName() . ' [' . $region->getId() . ']', NULL, 'shoppingfeed_customer.log');  // temporary data log
            }
            unset($region);
        }
        // for international orders, if not able to set US state, then do the default Shopping Feed functionality 
        if (!$didSetUSState) {
            $address->setStreet(array($data['Street1'], $data['Street2']));
        }
            
            /*$regionCode = trim($data['Street2']);

            if (!preg_match('/^[a-z]{2}$/i', $regionCode)) {
                $regionCode = null;
            } else {
                $isAddressRegionCode = true;
            }*?
        }

        if ($regionCode) {
            /** @var Mage_Directory_Model_Resource_Region_Collection $regionCollection */
            $regionCollection = Mage::getResourceModel('directory/region_collection');
            $regionCollection->addRegionCodeFilter($regionCode);
            $regionCollection->addCountryFilter($address->getCountry());

            if ($regionCollection->getSize() > 0) {
                $regionCollection->setCurPage(1);
                $regionCollection->setPageSize(1);
                $regionId = $regionCollection->getFirstItem()->getId();
            } else {
                $regionId = false;
            }
        }

        if ($isAddressRegionCode && !empty($regionId)) {
            $data['Street2'] = '';
        }

        $address->setStreet(array($data['Street1'], $data['Street2']));

        if ($regionId) {
            $address->setRegionId($regionId);
        } else {
            $address->setRegionId(182);
        }

        if ($limit = $config->getAddressLengthLimit($storeId)) {
            $truncatedStreet = array();

            foreach ($address->getStreet() as $streetRow) {
                if ($stringHelper->strlen($streetRow) > $limit) {
                    $truncatedStreet = array_merge(
                        $truncatedStreet,
                        $helper->truncateAddress($streetRow, $limit)
                    );
                } else {
                    $truncatedStreet[] = $streetRow;
                }
            }

            $address->setStreet($truncatedStreet);
        }

        return $address;
    }
}
