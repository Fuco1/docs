<?php

namespace Wiki;

use Texy,
	TexyLink,
	TexyHtml,
	FSHL,
	Nette,
	Nette\Utils\Strings;


/**
 * Texy parser for wiki.
 *
 * @copyright  David Grudl
 */
class Convertor extends Nette\Object
{
	/** @var Page */
	private $page;

	/** @var mixed */
	private $tocMode;

	/** @var array */
	public $paths = [
		'mediaPath' => NULL,
		'fileMediaPath' => NULL,
		'apiUrl' => NULL,
		'downloadDir' => NULL,
		'domain' => NULL,
		'profileUrl' => NULL,
	];

	/** @var PageId[] */
	public $links;

	/** @var string[] */
	public $errors;


	public function __construct(array $paths = array())
	{
		$this->paths = $paths + $this->paths;
	}


	/**
	 * @return Page
	 */
	public function parse(PageId $id, $text)
	{
		$this->tocMode = $this->errors = $this->links = NULL;

		$this->page = $page = new Page;
		$page->id = clone $id;
		$page->sidebar = TRUE;

		$texy = $this->createTexy();
		$page->html = $texy->process($text);
		$page->title = $texy->headingModule->title;

		if ($this->tocMode === NULL) {
			$this->tocMode = strlen($page->html) > 4000;
		}
		if ($this->tocMode) {
			foreach ($texy->headingModule->TOC as $item) {
				if ($item['el']->id && !empty($item['title'])) {
					$page->toc[] = (object) [
						'level' => $item['level'],
						'title' => $item['title'],
						'id' => $item['el']->id,
					];
				}
			}
			if ($page->toc && $this->tocMode === 'title') {
				$page->toc[0]->level++;
			} else {
				unset($page->toc[0]);
			}
		}

		return $page;
	}


	/**
	 * @return Texy
	 */
	public function createTexy()
	{
		$texy = new Texy;
		$texy->linkModule->root = '';
		$texy->alignClasses['left'] = 'left';
		$texy->alignClasses['right'] = 'right';
		$texy->emoticonModule->class = 'smiley';
		$texy->headingModule->top = 1;
		$texy->headingModule->generateID = TRUE;
		$texy->tabWidth = 4;
		$texy->typographyModule->locale = $this->page->id->lang;
		$texy->tableModule->evenClass = 'alt';
		$texy->dtd['body'][1]['style'] = TRUE;
		$texy->allowed['longwords'] = FALSE;
		$texy->allowed['block/html'] = FALSE;

		$texy->phraseModule->tags['phrase/strong'] = 'b';
		$texy->phraseModule->tags['phrase/em'] = 'i';
		$texy->phraseModule->tags['phrase/em-alt'] = 'i';
		$texy->phraseModule->tags['phrase/acronym'] = 'abbr';
		$texy->phraseModule->tags['phrase/acronym-alt'] = 'abbr';

		$texy->addHandler('block', array($this, 'blockHandler'));
		$texy->addHandler('script', array($this, 'scriptHandler'));
		$texy->addHandler('phrase', array($this, 'phraseHandler'));
		$texy->addHandler('newReference', array($this, 'newReferenceHandler'));
		return $texy;
	}


	/********************* text tools ****************d*g**/


	public function resolveLink($link, & $label = NULL)
	{
		if (preg_match('~.+@|https?:|ftp:|mailto:|ftp\.|www\.~Ai', $link)) { // external link
			return $link;

		} elseif (substr($link, 0, 1) === '#') { // section link
			if (Strings::startsWith($link, '#toc-')) {
				$link = substr($link, 5);
			}
			return '#toc-' . Strings::webalize($link);
		}

		preg_match('~^
			(?:(?P<book>[a-z]{3,}(?:-\d\.\d)?):)?
			(?:[:/]?(?P<lang>[a-z]{2})(?=[:/#]|$))?
			(?P<name>[^#]+)?
			(?:\#(?P<section>.*))?
		$~x', $link, $matches);

		if (!$matches) {
			return $link; // invalid link
		}

		// normalize name
		$matches = (object) $matches;
		$name = isset($matches->name) ? $matches->name : '';
		$name = rtrim(strtr($name, ':', '/'), '/');

		if (trim(strtolower($name), '/') === Page::HOMEPAGE || $name === '') {
			$name = Page::HOMEPAGE;
		}

		if (substr($name, 0, 1) !== '/' && empty($matches->book) && empty($matches->lang) && ($a = strrpos($this->page->id->name, '/'))) { // absolute name
			$name = substr($this->page->id->name, 0, $a + 1) . $name;
		}

		$name = trim($name, '/');
		$book = empty($matches->book) ? ($this->page->id->book === 'meta' ? 'www' : $this->page->id->book) : $matches->book;
		$lang = empty($matches->lang) ? $this->page->id->lang : $matches->lang;
		$section = isset($matches->section) ? $matches->section : '';


		// generate URL
		if ($book === 'download') {
			return $this->paths['downloadDir'] . '/' . $name;

		} elseif ($book === 'attachment') {
			if (!is_file($this->paths['fileMediaPath'] . '/' . $this->page->id->book . '/' . $name)) {
				$this->errors[] = "Missing file $name";
			}
			return $this->paths['mediaPath'] . '/' . $this->page->id->book . '/' . $name;

		} elseif ($book === 'api') {
			$path = strtr($matches->name, '\\', '.');

			if (strpos($path, '()') !== FALSE) { // method
				$path = str_replace('::', '.html#_', str_replace('()', '', $path));

			} elseif (strpos($path, '::') !== FALSE) { // var & const
				$path = str_replace('::', '.html#', $path);

			} else { // class
				$path .= '.html';
			}
			return $this->paths['apiUrl'] . '/' . $path;

		} elseif ($book === 'user') {
			return $this->paths['profileUrl'] . (int) $matches->name;

		} elseif ($book === 'php') {
			return 'http://php.net/' . urlencode($matches->name) . ($section ? "#$section" : ''); // not good - language?

		} else {
			if (Strings::startsWith($section, 'toc-')) {
				$section = substr($section, 4);
			}
			return new PageId($book, $lang, $name, $section ? 'toc-' . Strings::webalize($section) : NULL);
		}
	}


	public function createUrl(PageId $link)
	{
		$parts = explode('-', $link->book, 2);
		$name = Strings::webalize($link->name, '/');
		return ($this->page->id->book === $link->book ? '' : 'http://' . ($parts[0] === 'www' ? '' : "$parts[0].") . $this->paths['domain'])
			. '/'
			. $link->lang . '/'
			. (isset($parts[1]) ? "$parts[1]/" : '')
			. ($name === Page::HOMEPAGE ? '' : $name)
			. ($link->fragment ? "#$link->fragment" : '');
	}


	/********************* Texy handlers ****************d*g**/


	/**
	 * @param  TexyHandlerInvocation  handler invocation
	 * @param  string  command
	 * @param  array   arguments
	 * @param  string  arguments in raw format
	 * @return TexyHtml|string|FALSE
	 */
	public function scriptHandler($invocation, $cmd, $args, $raw)
	{
		$texy = $invocation->getTexy();
		$page = $this->page;
		switch ($cmd) {
		case 'nofollow':
			$texy->linkModule->forceNoFollow = !count($args) || $args[0] !== 'no';
			break;

		case 'title':
			$texy->headingModule->title = $raw;
			break;

		case 'lang':
			$link = $this->resolveLink($args[0]);
			if ($link instanceof PageId) {
				$page->langs[$link->lang] = $link->name;
			}
			break;

		case 'tags':
			foreach ($args as $tag) {
				$tag = trim($tag);
				if ($tag !== '') {
					$page->tags[] = $tag;
				}
			}
			break;

		case 'toc':
			$this->tocMode = $raw === 'no' ? FALSE : $raw;
			break;

		case 'sidebar':
			$page->sidebar = $raw !== 'no';
			break;

		case 'theme':
			if ($raw === 'homepage') {
				$texy->headingModule->top = 2;
			}
			$page->theme = $raw;
			break;

		case 'maintitle':
			$page->mainTitle = $raw;
			break;

		default:
			$this->errors[] = 'Unknown {{'.$cmd.'}}';
		}
		return '';
	}


	/**
	 * @param  TexyHandlerInvocation  handler invocation
	 * @param  string
	 * @param  string
	 * @param  TexyModifier
	 * @param  TexyLink
	 * @return TexyHtml|string|FALSE
	 */
	public function phraseHandler($invocation, $phrase, $content, $modifier, $link)
	{
		if (!$link) {
			$el = $invocation->proceed();
			if ($el instanceof TexyHtml && $el->getName() !== 'a' && $el->title !== NULL) {
				$el->class[] = 'about';
			}
			return $el;
		}

		if ($link->type === 2 && in_array(rtrim($link->URL, ':'), array('api', 'php'))) { // [api] [php]
			$link->URL = rtrim($link->URL, ':') . ':' . $content;
		}

		$dest = $this->resolveLink($link->URL);
		if ($dest instanceof PageId) {
			$link->URL = $this->createUrl($dest);
			$dest->name = Strings::webalize($dest->name, '/');
			$dest->fragment = NULL;
			$this->links[] = $dest;
		} else {
			$link->URL = $dest;
		}

		return $invocation->proceed($phrase, $content, $modifier, $link);
	}


	/**
	 * @param  TexyHandlerInvocation  handler invocation
	 * @param  string
	 * @return TexyHtml|string|FALSE
	 */
	public function newReferenceHandler($invocation, $name)
	{
		$texy = $invocation->getTexy();

		$dest = $this->resolveLink($dest);
		if ($dest instanceof PageId) {
			if (!isset($label)) {
				$label = explode('/', $dest->name);
				$label = end($label);
			}
			$el = $texy->linkModule->solve(NULL, new \TexyLink($this->createUrl($dest)), $label);
			if ($dest->lang !== $this->page->id->lang) $el->lang = $dest->lang;

			$dest->name = Strings::webalize($dest->name, '/');
			$dest->fragment = NULL;
			$this->links[] = $dest;

		} else {
			if (!isset($label)) {
				$label = preg_replace('#(?!http|ftp|mailto)[a-z]+:|\##A', '', $name); // [api:...], [#section]
			}
			$el = $texy->linkModule->solve(NULL, $texy->linkModule->factoryLink("[$dest]", NULL, $label), $label);
		}
		return $el;
	}


	/**
	 * User handler for code block.
	 *
	 * @param  TexyHandlerInvocation  handler invocation
	 * @param  string  block type
	 * @param  string  text to highlight
	 * @param  string  language
	 * @param  TexyModifier modifier
	 * @return TexyHtml
	 */
	public function blockHandler($invocation, $blocktype, $content, $lang, $modifier)
	{
		if (preg_match('#^block/(php|neon|javascript|js|css|html|htmlcb|latte)$#', $blocktype)) {
			list(, $lang) = explode('/', $blocktype);

		} elseif ($blocktype !== 'block/code') {
			return $invocation->proceed();
		}

		$lang = strtolower($lang);
		if ($lang === 'htmlcb' || $lang === 'latte') $lang = 'html';
		elseif ($lang === 'javascript') $lang = 'js';

		if ($lang === 'html') $langClass = 'FSHL\Lexer\LatteHtml';
		elseif ($lang === 'js') $langClass = 'FSHL\Lexer\LatteJavascript';
		else $langClass = 'FSHL\Lexer\\' . ucfirst($lang);

		$texy = $invocation->getTexy();
		$content = Texy::outdent($content);

		if (class_exists($langClass)) {
			$fshl = new FSHL\Highlighter(new FSHL\Output\Html, FSHL\Highlighter::OPTION_TAB_INDENT);
			$content = $fshl->highlight($content, new $langClass);
		} else {
			$content = htmlSpecialChars($content);
		}
		$content = $texy->protect($content, Texy::CONTENT_BLOCK);

		$elPre = TexyHtml::el('pre');
		if ($modifier) {
			$modifier->decorate($texy, $elPre);
		}
		$elPre->attrs['class'] = 'src-' . strtolower($lang);

		$elCode = $elPre->create('code', $content);

		return $elPre;
	}

}
