<?php
/**
 * External Links v1.0.1
 *
 * This plugin adds small icons to external and mailto links, informing
 * users the link will take them to a new site or open their email client.
 *
 * Licensed under MIT, see LICENSE.
 *
 * @package     External Links
 * @version     1.0.1
 * @link        <https://github.com/sommerregen/grav-plugin-archive-plus>
 * @author      Benjamin Regler <sommergen@benjamin-regler.de>
 * @copyright   2015, Benjamin Regler
 * @license     <http://opensource.org/licenses/MIT>            MIT
 */

namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Utils;
use Grav\Common\Plugin;
use Grav\Common\Page\Page;
use Grav\Common\Data\Data;
use Grav\Common\Inflector;
use RocketTheme\Toolbox\Event\Event;

/**
 * External Links Plugin
 *
 * This plugin adds small icons to external and mailto links, informing
 * users the link will take them to a new site or open their email client.
 */
class ExternalLinksPlugin extends Plugin {
  /**
   * @var ExternaLinksPlugin
   */

  /** -------------
   * Public methods
   * --------------
   */

  /**
   * Return a list of subscribed events.
   *
   * @return array    The list of events of the plugin of the form
   *                      'name' => ['method_name', priority].
   */
  public static function getSubscribedEvents() {
    return [
      'onPluginsInitialized' => ['onPluginsInitialized', 0],
    ];
  }

  /**
   * Initialize configuration.
   */
  public function onPluginsInitialized() {
    if ($this->isAdmin()) {
      $this->active = false;
      return;
    }

    if ( $this->config->get('plugins.external_links.enabled') ) {
      $weight = $this->config->get('plugins.external_links.weight');
      $this->enable([
        // Process contents order according to weight option
        'onPageContentProcessed' => ['onPageContentProcessed', $weight],
        'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
      ]);
    }
  }

  /**
   * Apply drop caps filter to content, when each page has not been
   * cached yet.
   *
   * @param  Event  $event The event when 'onPageContentProcessed' was
   *                       fired.
   */
  public function onPageContentProcessed(Event $event) {
    /** @var Page $page */
    $page = $event['page'];
    $config = $this->mergeConfig($page);

    // Modify page content only once
    $process = $config->get('external_links.process', FALSE);
    if ( $config->get('process.external_links', $process) AND $this->compileOnce($page) ) {
      $content = $page->getRawContent();

      // Create a DOM parser object
      $dom = new \DOMDocument('1.0', 'UTF-8');

      // Pretty print output
      $dom->preserveWhiteSpace = FALSE;
      $dom->formatOutput       = TRUE;

      // Parse the HTML using UTF-8
      // The @ before the method call suppresses any warnings that
      // loadHTML might throw because of invalid HTML in the page.
      @$dom->loadHTML($content);

      // Do nothing, if DOM is empty or a route for a given page
      // does not exist
      if ( is_null($dom->documentElement) OR !$page->routable() ) {
        return;
      }

      $links = $dom->getElementsByTagName('a');
      foreach ( $links as $a ) {
        // Process link with href attribute only, if it is non-empty
        $href = $a->getAttribute('href');
        if ( strlen($href) == 0 ) {
          continue;
        }

        // Get the class of the <a> element
        $class = $a->hasAttribute('class') ? $a->getAttribute('class') : '';
        $classes = array_filter(explode(' ', $class));

        $exclude = $config->get('external_links.exclude.classes');
        if ( $exclude AND in_array($exclude, $classes) ) {
          continue;
        }

        // This is a mailto link.
        if ( strpos($href, 'mailto:') === 0 ) {
          $classes[] = 'mailto';
        }

        // The link is external
        elseif ( $this->isExternalUrl($href) ) {
          // Add external class
          $classes[] = 'external';

          // Add target="_blank"
          $target = $config->get('external_links.target');
          if ( $target ) {
            $a->setAttribute('target', $target);
          }

          // Add no-follow.
          $nofollow = $config->get('external_links.no_follow');
          if ( $nofollow ) {
            $rel = array_filter(explode(' ', $a->getAttribute('rel')));
            if ( !in_array('nofollow', $rel) ) {
              $rel[] = 'nofollow';
              $a->setAttribute('rel', implode(' ', $rel));
            }
          }

          // Add title (aka alert_text)
          //$title = $this->config->get('plugins.external_links.title');
          //$a-setAttribute('title', $title);
        }

        // Add image class to <a> if it one <img> child element exists
        $imgs = $a->getElementsByTagName('img');
        if ( $imgs->length > 1 ) {
          // Add "imgs" class to <a> element, if it has multiple child images
          $classes[] = 'imgs';
        } elseif ( $imgs->length == 1 ) {
          $imgNode = $imgs->item(0);

          // Get image size
          list($width, $height) = $this->getImageSize($imgNode);

          // Determine maximum dimension of image size
          $size = max($width, $height);

          // Depending on size determine image type
          $classes[] = ( (0 < $size) AND ($size <= 32) ) ? 'icon' : 'img';
        } else {
          // Add "no-img" class to <a> element, if it has no child images
          $classes[] = 'no-img';
        }

        // Set class attribute
        if ( count($classes) ) {
          $a->setAttribute('class', implode(' ', $classes));
        }
      }

      $content = '';
      // Process HTML from DOM document
      foreach ( $dom->documentElement->childNodes as $node ) {
        $content .= $dom->saveHTML($node);
      }

      $page->setRawcontent($content);
    }
  }

  /**
   * Set needed variables to display drop caps.
   */
  public function onTwigSiteVariables() {
    if ($this->config->get('plugins.external_links.built_in_css')) {
      $this->grav['assets']->add('plugin://external_links/css/external_links.css');
    }
  }

  /** -------------------------------
   * Private/protected helper methods
   * --------------------------------
   */

  /**
   * Checks if a page has already been compiled yet.
   *
   * @param  Page    $page The page to check
   * @return boolean       Returns TRUE if page has already been
   *                       compiled yet, FALSE otherwise
   */
  protected function compileOnce(Page $page) {
    static $processed = array();

    $id = md5($page->path());
    // Make sure that contents is only processed once
    if ( !isset($processed[$id]) OR ($processed[$id] < $page->modified()) ) {
      $processed[$id] = $page->modified();
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Merge global and page configurations.
   *
   * @param  Page   $page The page to merge the configurations with the
   *                      plugin settings.
   */
  protected function mergeConfig(Page $page) {
    static $className;

    if ( is_null($className) ) {
      // Load configuration based on class name
      $reflector = new \ReflectionClass($this);

      // Remove namespace and trailing "Plugin" word
      $name = $reflector->getShortName();
      $name = substr($name, 0, -strlen('Plugin'));

      // Guess configuration path from class name
      $class_formats = array(
        strtolower($name),                # all lowercased
        Inflector::underscorize($name),   # underscored
        );

      $defaults = array();
      // Try to load configuration
      foreach ( $class_formats as $name ) {
        if ( !is_null($this->config->get('plugins.' . $name, NULL)) ) {
          $className = $name;
          break;
        }
      }
    }

    // Get default plugin configurations and retrieve page header configuration
    $plugin = (array) $this->config->get('plugins.' . $className, array());
    $header = (array) $page->header();

    // Create new config data class
    $config = new Data();
    $config->setDefaults($header);
    $config->joinDefaults($className, $plugin);

    // Return configurations as a new data config class
    return $config;
  }

  /**
   * Test if a URL is external
   *
   * @param  string  $url The URL to test.
   * @return boolean      Returns TRUE, if the URL is external, FALSE
   *                      otherwise.
   */
  protected function isExternalUrl($url) {
    static $allowed_protocols;
    static $pattern;

    // Statically store allowed protocols
    if ( !isset($allowed_protocols) ) {
      $allowed_protocols = array_flip(array(
        'ftp', 'http', 'https', 'irc', 'mailto', 'news', 'nntp',
        'rtsp', 'sftp', 'ssh', 'tel', 'telnet', 'webcal')
      );
    }

    // Statically store internal domains as a PCRE pattern.
    if ( !isset($pattern) ) {
      $domains = array();
      $urls = (array) $this->config->get('plugins.external_links.exclude.domains');
      $urls = array_merge($urls, array($this->grav['base_url_absolute']));

      foreach ( $urls as $domain ) {
        $domains[] = preg_quote($domain, '#');
      }
      $pattern = '#^(' . str_replace('\*', '.*', implode('|', $domains)) . ')#';
    }

    $external = FALSE;
    if ( !preg_match($pattern, $url) ) {
      // Check if URL is external by extracting colon position
      $colonpos = strpos($url, ':');
      if ( $colonpos > 0 ) {
        // We found a colon, possibly a protocol. Verify.
        $protocol = strtolower(substr($url, 0, $colonpos));
        if ( isset($allowed_protocols[$protocol]) ) {
          // The protocol turns out be an allowed protocol
          $external = TRUE;
        }
      } elseif ( Utils::startsWith($url, 'www.') ) {
        // We found an url without protocol, but with starting
        // 'www' (sub-)domain
        $external = TRUE;
      }
    }

    // Only if a colon and a valid protocol was found return TRUE
    return ($colonpos !== FALSE) AND $external;
  }

  /**
   * Determine the size of an image
   *
   * @param  DOMNode $imgNode The image already parsed as a DOMNode
   * @return array            Return the dimension of the image of the
   *                          format array(width, height)
   */
  protected function getImageSize($imgNode) {
    // Hold units (assume standard font with 16px base pixel size)
    $units = array('px' => 1, 'pt' => 12, 'ex' => 6, 'em' => 12, 'rem' => 12);

    // Initialize dimensions
    $width = 0;
    $height = 0;

    // Determine image dimensions based on "src" atrribute
    if ( $imgNode->hasAttribute('src') ) {
      $src = $imgNode->getAttribute('src');

      // Simple check if the URL is internal i.e. check if path exists
      $path = $_SERVER['DOCUMENT_ROOT'] . $src;
      if ( realpath($path) AND is_file($path) ) {
        $size = @getimagesize($path);
      } else {
        // The URL is external; try to load it (max. 32 KB)
        $size = $this->getRemoteImageSize($src, 32 * 1024);
      }
    }

    // Read out width and height from <img> attributes
    $width = $imgNode->hasAttribute('width') ?
      $imgNode->getAttribute('width')  : $size[0];
    $height = $imgNode->hasAttribute('height') ?
      $imgNode->getAttribute('height')  : $size[1];

    // Get width and height from style attribute
    if ( $imgNode->hasAttribute('style') ) {
      $style = $imgNode->getAttribute('style');

      // Width
      if ( preg_match('~width:\s*(\d+)([a-z]+)~i', $style, $matches) ) {
        $width = $matches[1];
        // Convert unit to pixel
        if ( isset($units[$matches[2]]) ) {
          $width *= $units[$matches[2]];
        }
      }

      // Height
      if ( preg_match('~height:\s*(\d+)([a-z]+)~i', $style, $matches) ) {
        $height = $matches[1];
        // Convert unit to pixel
        if ( isset($units[$matches[2]]) ) {
          $height *= $units[$matches[2]];
        }
      }
    }

    // Update width and height
    $size[0] = $width;
    $size[1] = $height;

    // Return image dimensions
    return $size;
  }

  /**
   * Get the size of a remote image
   *
   * @param  string  $uri   The URI of the remote image
   * @param  integer $bytes Limit size for remote image; download only
   *                        up to n bytes.
   * @return mixed          Returns an array with up to 7 elements
   */
  protected function getRemoteImageSize($uri, $bytes = -1) {
    // Create temporary file to store data from $uri
    $tmp_name = tempnam(sys_get_temp_dir(), uniqid('gis'));
    if ( $tmp_name === FALSE ) {
      return FALSE;
    }

    // Open temporary file
    $tmp = fopen($tmp_name, 'rb');

    // Check which method we should use to get remote image sizes
    $allow_url_fopen = ini_get('allow_url_fopen') ? TRUE : FALSE;
    $use_curl = function_exists('curl_version');

    // Use stream copy
    if ( $allow_url_fopen ) {
      $options = array();
      if ( $bytes > 0 ) {
        // Loading number of $bytes
        $options['http']['header'] = array('Range: bytes=0-' . $bytes);
      }

      // Create stream context
      $context = stream_context_create($options);
      @copy($uri, $tmp_name, $context);

    // Use Curl
    } elseif ( $use_curl ) {
      // Initialize Curl
      $options = array(
        CURLOPT_HEADER => FALSE,            // Don't return headers
        CURLOPT_FOLLOWLOCATION => TRUE,     // Follow redirects
        CURLOPT_AUTOREFERER => TRUE,        // Set referer on redirect
        CURLOPT_CONNECTTIMEOUT => 120,      // Timeout on connect
        CURLOPT_TIMEOUT => 120,             // Timeout on response
        CURLOPT_MAXREDIRS => 10,            // Stop after 10 redirects
        CURLOPT_ENCODING => '',             // Handle all encodings
        CURLOPT_BINARYTRANSFER => TRUE,     // Transfer as binary file
        CURLOPT_FILE => $tmp,               // Curl file
        CURLOPT_URL => $uri,                // URI
      );

      $curl = curl_init();
      curl_setopt_array($curl, $options);

      if ( $bytes > 0 ) {
        // Loading number of $bytes
        $headers = array('Range: bytes=0-' . $bytes);
        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($curl, CURLOPT_RANGE, '0-' . $bytes);

        // Abort request when more data is received
        curl_setopt($curl, CURLOPT_BUFFERSIZE, 512);    // More progress info
        curl_setopt($curl, CURLOPT_NOPROGRESS, FALSE);  // Monitor progress
        curl_setopt($curl, CURLOPT_PROGRESSFUNCTION,
          function($download_size, $downloaded, $upload_size, $uploaded) use ($bytes) {
            // If $downloaded exceeds $bytes, returning non-0 breaks
            // the connection!
            return ( $downloaded > $bytes ) ? 1 : 0;
        });
      }

      // Execute Curl
      curl_exec($curl);
      curl_close($curl);
    }

    // Close temporary file
    fclose($tmp);

    // Retrieve image information
    $info = array(0, 0, 'width="0" height="0"');
    if ( filesize($tmp_name) > 0 ) {
      $info = @getimagesize($tmp_name);
    }

    // Delete temporary file
    unlink($tmp_name);

    return $info;
  }
}
