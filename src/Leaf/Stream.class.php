<?php
namespace Leaf;

use Leaf\TagsManager;
use Leaf\TagStrategies;
use Leaf\TemplateStrategies;
use Leaf\CodeStrategies;
use Leaf\TagModifiers;
class Stream
{
  private $Document;
  private $mode;
  private $url;
  private $opened_path;
  private $options       = array();
  private $eof           = false;
  private $current_index = 0;
  private $is_builded    = false;

  private $TagsManager;

  public static $NS       = 'leaf';
  public static $SCHEME   = 'leaf';
  public static $CACHEDIR = '._Cache/';
  const DIR_SEPARATOR = '_';

  public function __construct()
  {
    $this->Document    = new Document();
    $this->TagsManager = new Nodes\Manager($this->Document);
  }

  public function getDom() { return ($this->Document); }
  public function getFilename() { return ($this->opened_path ?: ($this->url['scheme']? : '').(isset($this->url['path']) ? $this->url['path'] : '')); }
  public function getCachename() { return (self::$CACHEDIR.str_replace('/', self::DIR_SEPARATOR, $this->getFilename())); }
  public function getTagsManager() { return ($this->TagsManager); }

  public static function __load()
  {
    stream_wrapper_register(self::$SCHEME, 'Leaf\\Stream') or die("Failed to register protocol");
  }
  public function cache_is_active()
  {
    return (!isset($this->options['cache']) || ((bool) $this->options['cache'] && $this->options['cache'] !== 'false'));
  }

  public function stream_eof()
  {
    return ((bool)$this->eof);
  }

  public function stream_stat()
  {
    if (file_exists($this->getFilename())) {
      return (stat($this->getFilename()));
    }
  }
  private function mergeWith($Parent_document)
  {
    $blocks       = $Parent_document->getElementsByTagNameNS('LeafTemplate', 'block');
    $child_blocks = $this->Document->getElementsByTagNameNS('LeafTemplate', 'block');

    foreach ($blocks as $ParentBlock) {
      $blockId = $ParentBlock->getAttribute("value");

      for ($i = $child_blocks->length - 1; $i >= 0; $i--) {
        $ChildBlock = $child_blocks->item($i);

         if ($ChildBlock->getAttribute("value") == $blockId) {
          $ChildBlock = $Parent_document->importNode($ChildBlock, true);
          $OldNode    = $ParentBlock->parentNode->replaceChild($ChildBlock, $ParentBlock);
          $tplParent  = $ChildBlock->getElementsByTagNameNS('LeafTemplate', 'parent')->item(0);

          if ($tplParent !== null) {
            while ($OldNode->childNodes->length > 0) {
              $tplParent->appendChild($OldNode->childNodes->item(0));
            }
          }
        }
      }
    }
    $this->Document = $Parent_document;
    return ($Parent_document);
  }

  public function stream_open($path, $mode, $options = array(), &$opened_path = null)
  {
    $this->url         = parse_url(substr($path, strlen(self::$SCHEME) + 3));
    $this->opened_path = $opened_path;
    $this->mode        = $mode;

    if (isset($this->url['query'])) {
      parse_str($this->url['query'], $output);
      $this->options += $output;
    }
    $this->url['scheme'] = isset($this->url['scheme']) ? $this->url['scheme'] . ':' : '';
    return (true);
  }

  public function buildDocument() {
    try {
      $Parser         = new LeafParser($this);
      $template_nodes = $this->Document->getElementsByTagNameNS('LeafTemplate', 'extends');
      $extends        = $template_nodes->item(0);

      if ($extends !== null && $extends->getAttribute('value')) {
        $val      = $extends->getAttribute('value');
        $new_file = $val[0] == '/' ? $val : (($this->url['scheme']? : '') . dirname($this->url['path']) . DIRECTORY_SEPARATOR . $val);
        $Parent   = new Stream();

        $Parent->stream_open(sprintf('%s://%s', self::$SCHEME, $new_file), $this->mode);
        $this->mergeWith($Parent->buildDocument());
      }
      return ($this->Document);
    }
    catch (\Exception $E) {
      throw new \Exception($E->getMessage(), $E->getCode());
    }
  }

  public function stream_read($count)
  {
    if (!$this->eof || !$count) {
      if ($this->need_to_rebuild()) {
        if (!$this->is_builded) {
          $this->is_builded = $this->buildDocument();
        }
        $this->eof = ($this->Document->childNodes->length <= $this->current_index);
        if (!$this->eof) {
          return ($this->Document->childNodes->item($this->current_index++)->__toHTML());
        }
      }
      else {
        $this->eof = true;
        return (file_get_contents($this->getCachename()));
      }
    }
    return ('');
  }

  public function need_to_rebuild()
  {
    return (!$this->cache_is_active() || (!file_exists($this->getCachename()) || filemtime($this->getFilename()) > filemtime($this->getCachename())));
  }

  public function stream_flush()
  {
    if ($this->cache_is_active() && $this->need_to_rebuild()) {
      if (!file_exists(self::$CACHEDIR)) {
        mkdir(self::$CACHEDIR);
      }
      touch($this->getCachename(), filemtime($this->getFilename()));
      file_put_contents($this->getCachename(), $this->Document->__toHtml());
    }
  }
}
?>