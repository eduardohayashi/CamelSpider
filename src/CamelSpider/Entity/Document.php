<?php

namespace CamelSpider\Entity;

use CamelSpider\Entity\AbstractSpiderEgg,
    Symfony\Component\DomCrawler\Crawler,
    Symfony\Component\BrowserKit\Response,
    CamelSpider\Spider\SpiderAsserts,
    CamelSpider\Spider\SpiderDom,
    CamelSpider\Entity\InterfaceSubscription,
    CamelSpider\Tools\Urlizer;

/**
 * Contain formated response
 *
 * @package     CamelSpider
 * @subpackage  Entity
 * @author      Gilmar Pupo <g@g1mr.com>
 *
 */

class Document extends AbstractSpiderEgg
{
    protected $name = 'Document';
    private $crawler;
    private $response;
    private $subscription;
    private $asserts;
    private $bigger = NULL;

    /**
     * Recebe a response HTTP e também dados da assinatura,
     * para alimentar os filtros que definem a relevânca do
     * conteúdo
     *
     * Config:
     *
     *
     * @param array $dependency Logger, Cache, array Config
     *
     **/
    public function __construct($uri, Crawler $crawler,
       InterfaceSubscription $subscription, $dependency = null)
    {
        $this->crawler = $crawler;
        $this->subscription = $subscription;
        if($dependency){
            foreach(array('logger', 'cache') as $k){
                if(isset($dependency[$k])){
                    $this->$k = $dependency[$k];
                }
            }
        }
        $config = isset($dependency['config']) ? $dependency['config'] : null;
        parent::__construct(array('relevancy'=>0, 'uri' => $uri), $config);
        $this->processResponse();
    }

    public function getHtml()
    {
        if ($this->bigger) {
            return $this->preStringProccess(
                SpiderDom::toCleanHtml($this->bigger)
            );
        }
    }

    /**
     * Verificar data container se link já foi catalogado.
     * Se sim, fazer idiff e rejeitar se a diferença for inferior a x%
     * Aplicar filtros contidos em $this->subscription
     **/
    public function getRelevancy()
    {
        return $this->get('relevancy');
    }

    public function getSlug()
    {
        return $this->get('slug');
    }

    public function getText()
    {
        return $this->get('text');
    }

    public function getTitle()
    {
        return $this->get('title');
    }

    public function setUri($uri)
    {
        $this->logger('setting Uri as [' . $uri . ']', 'info', 3);

        return $this->set('uri', $uri);
    }

    public function getUri()
    {
        return $this->get('uri');
    }

    /**
     * @return array $array
     */
    public function toArray()
    {
        $array = array(
            'relevancy' => $this->getRelevancy(),
            'title'     => $this->getTitle(),
        );

        return $array;
    }

    /**
     * reduce memory usage
     *
     * @return self minimal
     */
    public function toPackage()
    {
        $array = array(
            'relevancy' => $this->getRelevancy(),
            'uri'       => $this->getUri(),
            'title'     => $this->getTitle(),
            'slug'      => $this->getSlug(),
            'text'      => $this->getText(),
            'html'      => $this->getHtml(),
            'raw'       => $this->getRaw()
        );

        return $array;
    }

    protected function addRelevancy()
    {
        $this->set('relevancy', $this->get('relevancy') + 1);
        $this->logger('Current relevancy:'. $this->getRelevancy(), 'info', 5);
    }

    protected function getBody()
    {
        return $this->crawler->filter('body');
    }

    protected function getBiggerTag()
    {
        foreach(array('div', 'td', 'span') as $tag){
            $this->searchBiggerInTags($tag);
        }
        if(! $this->bigger instanceof \DOMElement ) {
            $this->logger('Cannot find bigger', 'info', 5);

            return false;
        }
    }

    protected function getRaw()
    {
        if ($this->getBody() instanceof DOMElement) {
            return SpiderDom::toHtml($this->getBody());
        } else {
            return 'SpiderDom toHtml with problems!';
        }
    }

    /**
     * Check some sources chars
     */
    protected function entityStringProccess($string)
    {
        return utf8_decode(
            html_entity_decode($string)
        );
    }

    protected function preStringProccess($string)
    {
        return trim($string);
    }

    protected function processResponse()
    {
        $this->logger('processing document' ,'info', 5);
        $this->getBiggerTag();

        if ($this->getConfig('save_document', false)) {
            $this->saveBiggerToFile();
        }

        $this->setText();
        $this->setRelevancy();
        $this->setTitle();
        $this->setSlug();
    }

    protected function saveBiggerToFile()
    {
        $title = '# '. $this->getTitle() . "\n\n";
        $this->cache->saveToHtmlFile($this->getHtml(), $this->get('slug'));
        $this->cache->saveDomToTxtFile($this->bigger, $this->get('slug'), $title);
    }

    /**
     * localiza a tag filha de body que possui maior
     * quantidade de texto
     */
    protected function searchBiggerInTags($tag)
    {
        $data = $this->crawler->filter($tag);

        foreach(clone $data as $node)
        {
            if(SpiderDom::containerCandidate($node)){
                $this->bigger = SpiderDom::getGreater($node, $this->bigger, array());
            }
        }
    }

    /**
     * Faz query no documento, de acordo com os parâmetros definidos
     * na assinatura e define a relevância, sendo que esta relevância 
     * pode ser:
     *  1) Possivelmente contém conteúdo
     *  2) Contém conteúdo e contém uma ou mais palavras chave desejadas 
     *  pela assinatura ou não contém palavras indesejadas
     *  3) Contém conteúdo, contém palavras desejadas e não contém 
     *  palavras indesejadas
     **/
    protected function setRelevancy()
    {
        if(!$this->bigger)
        {
            $this->logger('Content too short', 'info', 5);
            return false;
        }
        $this->addRelevancy();//+1 cause text exist

        $txt = $this->getTitle() . "\n"  . $this->getText();

        $this->logger("Text to be verified:\n". $txt . "\n", 'info', 5);

        //diseribles keywords filter
        if (is_null($this->subscription->getFilter('contain'))) {
            $this->addRelevancy();
            $this->logger('ignore keywords filter', 'info' , 5);
        } else {
            //Contain?
            $this->logger(
                'Check for keywords['
                . implode(',', $this->subscription->getFilter('contain'))
                . ']', 'info', 4
            );
            $containTest = SpiderAsserts::containKeywords(
                $txt, (array) $this->subscription->getFilter('contain'), true
            );
            if($containTest) {
                $this->addRelevancy();
            } else {
                $this->logger('Document not contain keywords', 'info', 5);
            }
        }

        //Bad words
        if (is_null($this->subscription->getFilter('notContain'))) {
            $this->addRelevancy();
            $this->logger('ignore Bad keywords filter', 'info' , 5);
        } else {
            //Not Contain?
            $this->logger(
                'Check for BAD keywords['
                . implode(',', $this->subscription->getFilter('notContain'))
                . ']', 'info', 5
            );
            if(
                !SpiderAsserts::containKeywords(
                    $txt, $this->subscription->getFilter('notContain'), false
                )
            ) {
                $this->addRelevancy();
            } else {
                $this->logger('Document contain BAD keywords', 'info', 5);
            }
        }
    }

    protected function setSlug()
    {
        $this->set('slug', substr(Urlizer::urlize($this->get('title')), 0, 30));
    }

    /**
     * Converte o elemento com maior probabilidade de
     * ser o container do conteúdo em plain text
     */
    protected function setText()
    {
        if($this->bigger){
            $this->set('text',
                $this->preStringProccess(
                    SpiderDom::toText($this->bigger)
                )
            );
        }
        else
        {
            $this->set('text', NULL);
        }
    }

    protected function setTitle()
    {
        $title = $this->crawler->filter('title')->text();
        $this->set('title', $this->preStringProccess($title));
        $this->logger('setting Title as [' . $this->getTitle() . ']', 'info', 3);
    }
}
