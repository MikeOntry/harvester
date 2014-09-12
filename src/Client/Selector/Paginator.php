<?php
namespace Erpk\Harvester\Client\Selector;

use XPathSelector\NodeInterface;

class Paginator
{
    protected $currentPage = null;
    protected $lastPage = null;
    
    protected function extractPage($str)
    {
        return (int)strtr($str->extract(), array('page_'=>''));
    }
    
    public function __construct($hxs)
    {
        if ($hxs instanceof XPath) {
            $pager = $hxs->select('//ul[@class="pager"][1]');
        
            if ($pager->hasResults()) {
                $last = $pager->select('//a[@class="last"][1]/@rel');
                $current = $pager->select('//a[@class="on"][1]/@rel');
                $lastSelectable = $pager->select('//li/a[position()=last()][1]');
                
                $this->currentPage = $current->hasResults() ? $this->extractPage($current) : null;
                $this->lastPage = $this->extractPage($last->hasResults() ? $last : $lastSelectable);
            }
        } else if ($hxs instanceof NodeInterface) {
            $pager = $hxs->find('//ul[@class="pager"][1]');
            $last = $pager->findAll('//a[@class="last"][1]/@rel');
            $current = $pager->findAll('//a[@class="on"][1]/@rel');
            $lastSelectable = $pager->find('//li/a[position()=last()][1]');
            $this->currentPage = $current->count() > 0 ? $this->extractPage($current->item(0)): null;
            $this->lastPage = $this->extractPage($last->count() > 0 ? $last->item(0) : $lastSelectable);
        }
    }
    
    public function getFirstPage()
    {
        return $this->firstPage;
    }
    
    public function getCurrentPage()
    {
        return $this->currentPage;
    }
    
    public function getLastPage()
    {
        return $this->lastPage;
    }
    
    public function toArray()
    {
        return [
            'current' => $this->currentPage,
            'last'    => $this->lastPage
        ];
    }
    
    public function isOutOfRange($page)
    {
        return $page > $this->lastPage;
    }
}
