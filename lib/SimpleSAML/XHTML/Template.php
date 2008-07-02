<?php

/**
 * A minimalistic XHTML PHP based template system implemented for simpleSAMLphp.
 *
 * @author Andreas Åkre Solberg, UNINETT AS. <andreas.solberg@uninett.no>
 * @package simpleSAMLphp
 * @version $Id$
 */
class SimpleSAML_XHTML_Template {

	/**
	 * This is the default language map. It is used to map languages codes from the user agent to
	 * other language codes.
	 */
	private static $defaultLanguageMap = array('nb' => 'no');


	private $configuration = null;
	private $template = 'default.php';
	private $language = null;
	
	private $langtext = null;
	
	public $data = null;

	/**
	 * Constructor
	 *
	 * @param $configuration   Configuration object
	 * @param $template        Which template file to load
	 * @param $languagefile    Optionally load a language file
	 */
	function __construct(SimpleSAML_Configuration $configuration, $template, $languagefile = null) {
		$this->configuration = $configuration;
		$this->template = $template;
		
		$this->data['baseurlpath'] = $this->configuration->getBaseURL();
		
		if (isset($_GET['language'])) {
			$this->setLanguage($_GET['language']);
		}
		
		if (!empty($languagefile)) $this->includeLanguageFile($languagefile);
	}
	
	/**
	 * setLanguage() will set a cookie for the user's browser to remember what language 
	 * was selected
	 * 
	 * @param $language    Language code for the language to set.
	 */
	public function setLanguage($language) {
		$this->language = $language;
		// setcookie ( string $name [, string $value [, int $expire [, string $path [, string $domain [, bool $secure [, bool $httponly ]]]]]] )
		// time()+60*60*24*900 expires 900 days from now.
		setcookie('language', $language, time()+60*60*24*900, '/');
	}

	/**
	 * getLanguage() will return the language selected by the user, or the default language
	 * This function first looks for a cached language code, 
	 * then checks for a language cookie,
	 * then it tries to calculate the preferred language from HTTP headers.
	 * Last it returns the default language.
	 */	
	public function getLanguage() {
		
		// Language is set in object
		if (isset($this->language)) {
			return $this->language;
		}

		// Language is provided in a stored COOKIE
		if (isset($_COOKIE['language'])) {
			$this->language = $_COOKIE['language'];
			return $this->language;
		}

		/* Check if we can find a good language from the Accept-Language http header. */
		$httpLanguage = $this->getHTTPLanguage();
		if ($httpLanguage !== NULL) {
			return $httpLanguage;
		}

		// Language is not set, and we get the default language from the configuration.
		return $this->getDefaultLanguage();
	}


	/**
	 * This function gets the prefered language for the user based on the Accept-Language http header.
	 *
	 * @return The prefered language based on the Accept-Language http header, or NULL if none of the
	 *         languages in the header were available.
	 */
	private function getHTTPLanguage() {
		$availableLanguages = $this->configuration->getValue('language.available');
		$languageScore = SimpleSAML_Utilities::getAcceptLanguage();

		/* For now we only use the default language map. We may use a configurable language map
		 * in the future.
		 */
		$languageMap = self::$defaultLanguageMap;

		/* Find the available language with the best score. */
		$bestLanguage = NULL;
		$bestScore = -1.0;

		foreach($languageScore as $language => $score) {

			/* Apply the language map to the language code. */
			if(array_key_exists($language, $languageMap)) {
				$language = $languageMap[$language];
			}

			if(!in_array($language, $availableLanguages, TRUE)) {
				/* Skip this language - we don't have it. */
				continue;
			}

			/* Some user agents use very limited precicion of the quality value, but order the
			 * elements in descending order. Therefore we rely on the order of the output from
			 * getAcceptLanguage() matching the order of the languages in the header when two
			 * languages have the same quality.
			 */
			if($score > $bestScore) {
				$bestLanguage = $language;
				$bestScore = $score;
			}
		}

		return $bestLanguage;
	}

	/**
	 * Returns the language base (from configuration)
	 */
	private function getBaseLanguage() {
		return $this->configuration->getValue('language.base', 'en');
	}
	
	/**
	 * Returns the language default (from configuration)
	 */
	private function getDefaultLanguage() {
		return $this->configuration->getValue('language.default', 'en');
	}

	/**
	 * Returns a list of all available languages.
	 */
	private function getLanguageList() {
		$availableLanguages = $this->configuration->getValue('language.available');
		$thisLang = $this->getLanguage();
		$lang = array();
		foreach ($availableLanguages AS $nl) {
			$lang[$nl] = ($nl == $thisLang);
		}
		return $lang;
	}

	/**
	 * Includs a file relative to the template base directory.
	 * This function can be used to include headers and footers etc.
	 *
	 */	
	private function includeAtTemplateBase($file) {
		$data = $this->data;
		$filename = $this->configuration->getPathValue('templatedir') . $this->configuration->getValue('theme.use') . '/' . $file;

		if (!file_exists($filename)) {
		
			SimpleSAML_Logger::error($_SERVER['PHP_SELF'].' - Template: Could not find template file [' . $file . 
				'] at [' . $filename . '] - Now trying at base');
			
			$filename = $this->configuration->getPathValue('templatedir') . $this->configuration->getValue('theme.base') . '/' . $file;
			if (!file_exists($filename)) {
				SimpleSAML_Logger::error($_SERVER['PHP_SELF'].' - Template: Could not find template file [' . $file . 
					'] at [' . $filename . ']');
				throw new Exception('Could not load template file [' . $file . ']');
			}
		
		} 
		
		include($filename);
	}


	/**
	 * Retrieve a tag.
	 *
	 * This function retrieves a tag as an array with language => string mappings.
	 *
	 * @param $tag  The tag name.
	 * @return As associative array with language => string mappings, or NULL if the tag wasn't found.
	 */
	public function getTag($tag) {
		assert('is_string($tag)');

		if(!array_key_exists($tag, $this->langtext)) {
			return NULL;
		}

		return $this->langtext[$tag];
	}


	/**
	 * Include text in the current language.
	 *
	 * @param $tag  A name tag of the string that should be returned.
	 * @param $replacements	 An associative array of keys that should be replaced with values in the translated string.
	 */
	public function t($tag, $replacements = array(), $fallbackdefault = true, $oldreplacements = array(), $striptags = false) {

		if(!is_array($replacements)) {

			/* Old style call to t(...). Print warning to log. */
			$backtrace = debug_backtrace();
			$where = $backtrace[0]['file'] . ':' . $backtrace[0]['line'];
			SimpleSAML_Logger::warning('Deprecated use of SimpleSAML_Template::t(...) at ' . $where .
				'. Please update the code to use the new style of parameters.');

			/* For backwards compatibility. */
			if(!$replacements && $this->getTag($tag) === NULL) {
				SimpleSAML_Logger::warning('Code which uses $fallbackdefault === FALSE shouls be' .
					' updated to use the getTag-method instead.');
				return NULL;
			}

			$replacements = $oldreplacements;
		}

		if (empty($this->langtext) || !is_array($this->langtext)) {
			SimpleSAML_Logger::error('Template: No language text loaded. Looking up [' . $tag . ']');
			return $this->t_not_translated($tag, TRUE);
		}

		$selected_language = $this->getLanguage();
		$default_language  = $this->getDefaultLanguage();
		$base_language     = $this->getBaseLanguage();

		$tagData = $this->getTag($tag);
		if($tagData === NULL) {
			/* Tag not found. */
			SimpleSAML_Logger::info('Template: Looking up [' . $tag . ']: not translated at all.');
			return $this->t_not_translated($tag, TRUE);
		}

		/**
		 * Look up translation of tag in the selected language
		 */
		if (array_key_exists($selected_language, $tagData)) {
			
			//SimpleSAML_Logger::debug('Template: Found up [' . $tag . ']: in selected language [' . $selected_language . ']. Text is: [' . $this->langtext[$tag][$selected_language] . '].');
			$translated =  $tagData[$selected_language];

		/**
		 * Look up translation of tag in the default language
		 */
		} elseif(array_key_exists($default_language, $tagData)) {
			SimpleSAML_Logger::info('Template: Looking up [' . $tag . ']: not found in language [' . $selected_language . '] using default [' . $default_language . '].');
			$translated =  $tagData[$default_language];

		/**
		 * Look up translation of tag in the base language
		 */
		} elseif(array_key_exists($base_language, $tagData)) {
			SimpleSAML_Logger::info('Template: Looking up [' . $tag . ']: not found in language default [' . $default_language . '] using base [' . $base_language . '].');
			$translated =  $tagData[$base_language];

		}
		foreach ($replacements as $k => $v) {
			/* try to translate if no replacement is given */
			if (!$v) $v = $this->t($k);
			$translated = str_replace($k, $v, $translated);
		}
		return $translated;
	}
	
	/**
	 * Return the string that should be used when no translation was found.
	 *
	 * @param $tag				A name tag of the string that should be returned.
	 * @param $fallbacktag		If set to TRUE and string was not found in any languages, return 
	 * 					the tag it self. If FALSE return NULL.
	 */
	private function t_not_translated($tag, $fallbacktag) {
		if ($fallbacktag) {
			return 'not translated (' . $tag . ')';
		} else {
			return NULL;
		}
	}
	
	
	/**
	 * You can include translation inline instead of putting translation
	 * in dictionaries. This function is reccomended to only be used from dynamic
	 * data, or when the translation is already provided from an external source, as
	 * a database or in metadata.
	 *
	 * @param $tag         The tag that has a translation
	 * @param $translation The translation array
	 */
	public function includeInlineTranslation($tag, $translation) {
		
		if (is_string($translation)) {
			$translation = array($this->getBaseLanguage() => $translation);
		} elseif (!is_array($translation)) {
			throw new Exception("Inline translation should be string or array. Is " . gettype($translation) . " now!");
		}
		if (!is_array($this->langtext)) 
			$this->langtext = array();	
		
		SimpleSAML_Logger::info('Template: Adding inline language translation for tag [' . $tag . ']');
		$this->langtext[$tag] = $translation;
	}
	
	/**
	 * Include language file from the dictionaries directory.
	 *
	 * @param $file         File name of dictionary to include
	 * @param $otherConfig  Optionally provide a different configuration object than
	 *  the one provided in the constructor to be used to find the dictionary directory.
	 *  This enables the possiblity of combining dictionaries inside simpleSAMLphp 
	 *  distribution with external dictionaries.
	 */
	public function includeLanguageFile($file, $otherConfig = null) {
		
		$filebase = null;
		if (!empty($otherConfig)) {
			$filebase = $otherConfig->getPathValue('dictionarydir');
		} else {
			$filebase = $this->configuration->getPathValue('dictionarydir');
		}
		
		
		SimpleSAML_Logger::info('Template: Loading [' . $filebase . $file . ']');
		
		if (!file_exists($filebase . $file)) {
			SimpleSAML_Logger::error($_SERVER['PHP_SELF'].' - Template: Could not find template file [' . $this->template . '] at [' . $filebase . $file . ']');
			return;
		}
		include($filebase . $file);
		if (isset($lang)) {
			if (is_array($this->langtext)) {
				SimpleSAML_Logger::info('Template: Merging language array. Loading [' . $file . ']');
				$this->langtext = array_merge($this->langtext, $lang);
			} else {
				SimpleSAML_Logger::info('Template: Setting new language array. Loading [' . $file . ']');
				$this->langtext = $lang;
			}
		}
		

		
	}

	/**
	 * Show the template to the user.
	 */
	public function show() {
	
		$filename  = $this->configuration->getPathValue('templatedir') . 
			$this->configuration->getValue('theme.use') . '/' . $this->template;
		

		if (!file_exists($filename)) {
			SimpleSAML_Logger::warning($_SERVER['PHP_SELF'].' - Template: Could not find template file [' . $this->template . '] at [' . $filename . '] - now trying the base template');
			
			
			$filename = $this->configuration->getPathValue('templatedir') . 
				$this->configuration->getValue('theme.base') . '/' . $this->template;
			

			if (!file_exists($filename)) {
				SimpleSAML_Logger::critical($_SERVER['PHP_SELF'].' - Template: Could not find template file [' . $this->template . '] at [' . $filename . ']');
			
				echo 'Fatal error: Could not find template file [' . $this->template . '] at [' . $filename . ']';
				exit(0);
			}
		}
		
		require_once($filename);
	}
	
	
}

?>