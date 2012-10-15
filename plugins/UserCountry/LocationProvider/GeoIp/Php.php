<?php
/**
 * Piwik - Open source web analytics
 * 
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 * @version $Id$
 * 
 * @category Piwik_Plugins
 * @package Piwik_UserCountry
 */

/**
 * A LocationProvider that uses the PHP implementation of GeoIP.
 * 
 * @package Piwik_UserCountry
 */
class Piwik_UserCountry_LocationProvider_GeoIp_Php extends Piwik_UserCountry_LocationProvider_GeoIp
{
	const ID = 'geoip_php';
	const TITLE = 'GeoIP (Php)';
	
	/**
	 * The GeoIP database instances used. This array will contain at most three
	 * of them: one for location info, one for ISP info and another for organization
	 * info.
	 * 
	 * Each instance is mapped w/ one of the following keys: 'loc', 'isp', 'org'
	 * 
	 * @var array of GeoIP instances
	 */
	private $geoIpCache = array();
	
	/**
	 * Closes all open geoip instances.
	 */
	public function __destruct()
	{
		foreach ($this->geoIpCache as $instance)
		{
			geoip_close($instance);
		}
	}
	
	/**
	 * Uses a GeoIP database to get a visitor's location based on their IP address.
	 * 
	 * This function will return different results based on the data used. If a city
	 * database is used, it may return the country code, region code, city name, area
	 * code, latitude, longitude and postal code of the visitor.
	 * 
	 * Alternatively, if used with a country database, only the country code will be
	 * returned.
	 * 
	 * @param array $info Must have an 'ip' field.
	 * @return array
	 */
	public function getLocation( $info )
	{
		$ip = $info['ip'];
		
		$result = array();
		
		$locationGeoIp = $this->getGeoIpInstance($key = 'loc');
		if ($locationGeoIp)
		{
			switch ($locationGeoIp->databaseType)
			{
				case GEOIP_CITY_EDITION_REV0: // city database type
				case GEOIP_CITY_EDITION_REV1:
				case GEOIP_CITYCOMBINED_EDITION:
					$location = @geoip_record_by_addr($locationGeoIp, $ip);
					if (!empty($location))
					{
						$result[self::COUNTRY_CODE_KEY] = $location->country_code;
						$result[self::REGION_CODE_KEY] = $location->region;
						$result[self::CITY_NAME_KEY] = utf8_encode($location->city);
						$result[self::AREA_CODE_KEY] = $location->area_code;
						$result[self::LATITUDE_KEY] = $location->latitude;
						$result[self::LONGITUDE_KEY] = $location->longitude;
						$result[self::POSTAL_CODE_KEY] = $location->postal_code;
					}
					break;
				case GEOIP_REGION_EDITION_REV0: // region database type
				case GEOIP_REGION_EDITION_REV1:
					$location = @geoip_region_by_addr($locationGeoIp, $ip);
					if (!empty($location))
					{
						$result[self::COUNTRY_CODE_KEY] = $location[0];
						$result[self::REGION_CODE_KEY] = $location[1];
					}
					break;
				case GEOIP_COUNTRY_EDITION: // country database type
					$result[self::COUNTRY_CODE_KEY] = @geoip_country_code_by_addr($locationGeoIp, $ip);
					break;
				default: // unknown database type, log warning and fallback to country edition
					Piwik::log("Found unrecognized database type: ".$locationGeoIp->databaseType);
					
					$result[self::COUNTRY_CODE_KEY] = @geoip_country_code_by_addr($locationGeoIp, $ip);
					break;
			}
		}
		
		// NOTE: ISP & ORG require commercial dbs to test. this code has been tested manually,
		// but not by integration tests.
		$ispGeoIp = $this->getGeoIpInstance($key = 'isp');
		if ($ispGeoIp)
		{
			$isp = @geoip_org_by_addr($ispGeoIp, $ip);
			if (!empty($isp))
			{
				$result[self::ISP_KEY] = utf8_encode($isp);
			}
		}
		
		$orgGeoIp = $this->getGeoIpInstance($key = 'org');
		if ($orgGeoIp)
		{
			$org = @geoip_org_by_addr($orgGeoIp, $ip);
			if (!empty($org))
			{
				$result[self::ORG_KEY] = utf8_encode($org);
			}
		}
		
		if (empty($result))
		{
			return false;
		}
		
		$this->completeLocationResult($result);
		return $result;
	}
	
	/**
	 * Returns true if this location provider is available. Piwik ships w/ the MaxMind
	 * PHP library, so this provider is available if a location GeoIP database can be found.
	 * 
	 * @return bool
	 */
	public function isAvailable()
	{
		$path = self::getPathToGeoIpDatabase(parent::$dbNames['loc']);
		return $path !== false;
	}
	
	/**
	 * Returns an array describing the types of location information this provider will
	 * return.
	 * 
	 * The location info this provider supports depends on what GeoIP databases it can
	 * find.
	 * 
	 * This provider will always support country & continent information.
	 * 
	 * If a region database is found, then region code & name information will be
	 * supported.
	 * 
	 * If a city database is found, then region code, region name, city name,
	 * area code, latitude, longitude & postal code are all supported.
	 * 
	 * If an organization database is found, organization information is
	 * supported.
	 * 
	 * If an ISP database is found, ISP information is supported.
	 * 
	 * @return array
	 */
	public function getSupportedLocationInfo()
	{
		$result = array();
		
		// country & continent info always available
		$result[self::CONTINENT_CODE_KEY] = true;
		$result[self::CONTINENT_NAME_KEY] = true;
		$result[self::COUNTRY_CODE_KEY] = true;
		$result[self::COUNTRY_NAME_KEY] = true;
		
		$locationGeoIp = $this->getGeoIpInstance($key = 'loc');
		if ($locationGeoIp)
		{		
			switch ($locationGeoIp->databaseType)
			{
				case GEOIP_CITY_EDITION_REV0: // city database type
				case GEOIP_CITY_EDITION_REV1:
				case GEOIP_CITYCOMBINED_EDITION:
					$result[self::REGION_CODE_KEY] = true;
					$result[self::REGION_NAME_KEY] = true;
					$result[self::CITY_NAME_KEY] = true;
					$result[self::AREA_CODE_KEY] = true;
					$result[self::LATITUDE_KEY] = true;
					$result[self::LONGITUDE_KEY] = true;
					$result[self::POSTAL_CODE_KEY] = true;
					break;
				case GEOIP_REGION_EDITION_REV0: // region database type
				case GEOIP_REGION_EDITION_REV1:
					$result[self::REGION_CODE_KEY] = true;
					$result[self::REGION_NAME_KEY] = true;
					break;
				default: // country or unknown database type
					break;
			}
		}
		
		// check if isp info is available
		if ($this->getGeoIpInstance($key = 'isp'))
		{
			$result[self::ISP_KEY] = true;
		}
		
		// check of org info is available
		if ($this->getGeoIpInstance($key = 'org'))
		{
			$result[self::ORG_KEY] = true;
		}
		
		return $result;
	}
	
	/**
	 * Returns information about this location provider. Contains an id, title & description:
	 * 
	 * array(
	 *     'id' => 'geoip_php',
	 *     'title' => '...',
	 *     'description' => '...'
	 * );
	 * 
	 * @return array
	 */
	public function getInfo()
	{
		$desc = Piwik_Translate('UserCountry_GeoIpLocationProviderDesc_Php1') . '<br/><br/>'
			  . Piwik_Translate('UserCountry_GeoIpLocationProviderDesc_Php2',
			  		array('<strong><em>', '</em></strong>', '<strong><em>', '</em></strong>'));
		$installDocs = '<em><a href="http://piwik.org/faq/how-to/#faq_163">'
	  		  . Piwik_Translate('UserCountry_HowToInstallGeoIPDatabases')
	  		  . '</em></a>';
		return array('id' => self::ID,
					  'title' => self::TITLE,
					  'description' => $desc,
					  'install_docs' => $installDocs,
					  'order' => 4);
	}
	
	/**
	 * Returns a GeoIP instance. Creates it if necessary.
	 * 
	 * @param string $key 'loc', 'isp' or 'org'. Determines the type of GeoIP database
	 *                    to load.
	 * @return object|false
	 */
	private function getGeoIpInstance( $key )
	{
		if (empty($this->geoIpCache[$key]))
		{
			// make sure region names are loaded & saved first
			parent::getRegionNames();
			require_once PIWIK_INCLUDE_PATH . '/libs/MaxMindGeoIP/geoipcity.inc';
			
			$pathToDb = self::getPathToGeoIpDatabase(parent::$dbNames[$key]);
			if ($pathToDb !== false)
			{
				$this->geoIpCache[$key] = geoip_open($pathToDb, GEOIP_STANDARD); // TODO support shared memory
			}
		}
		
		return empty($this->geoIpCache[$key]) ? false : $this->geoIpCache[$key];
	}
}
