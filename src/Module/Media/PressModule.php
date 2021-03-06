<?php
namespace Erpk\Harvester\Module\Media;

use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Filter;

class PressModule extends Module
{
    const CATEGORY_FIRST_STEPS = 1;
    const CATEGORY_BATTLE_ORDERS = 2;
    const CATEGORY_WARFARE_ANALYSIS = 3;
    const CATEGORY_POLITICAL_DEBATES_AND_ANALYSIS = 4;
    const CATEGORY_FINANCIAL_BUSINESS = 5;
    const CATEGORY_SOCIAL_INTERACTIONS_AND_ENTERTAINMENT = 6;
    
    public function publishArticle($articleName, $articleBody, $articleCategory)
    {
        $this->getClient()->checkLogin();
        $request = $this->getClient()->post('write-article');

        $request->getHeaders()
            ->set('Referer', $this->getClient()->getBaseUrl().'/write-article');
        $request->addPostFields(
            array(
                'article_name' => $articleName,
                'article_body' => $articleBody,
                'article_category' => $articleCategory,
                '_token'  => $this->getSession()->getToken()
            )
        );
        $response = $request->send();

        if ($response->isRedirect()) {
            return Article::createFromUrl(
                $response->getLocation()
            );
        } else {
            throw new ScrapeException;
        }
    }
    
    public function editArticle(Article $article, $articleName, $articleBody, $articleCategory)
    {
        $this->getClient()->checkLogin();
        $request = $this->getClient()->post('edit-article/'.$article->getId());
        $request
            ->getHeaders()
            ->set('Referer', $this->getClient()->getBaseUrl().'/edit-article/'.$article->getId());

        $request->addPostFields(
            array(
                'commit' => 'Edit',
                'article_name' => $articleName,
                'article_body' => $articleBody,
                'article_category' => $articleCategory,
                '_token' => $this->getSession()->getToken()
            )
        );
        $response = $request->send();
        return $response->getBody(true);
    }

    public function deleteArticle(Article $article)
    {
        $this->getClient()->checkLogin();
        $request = $this->getClient()->get('delete-article/'.$article->getId().'/1');
        $request->send();
    }

    public function getNewspaper($id, $pageLimit=null)
    {
        $id = Filter::id($id);
        $this->getClient()->checkLogin();

        $response = $this->getClient()->get('newspaper/'.$id)->send();

        if ($response->isRedirect()) {
            $location = 'http://www.erepublik.com'.$response->getLocation();
            if (strpos($location, 'http://www.erepublik.com/en/newspaper/') !== false) {
                $response = $this->getClient()->get($location)->send();
            } else {
                throw new NotFoundException('Newspaper does not exist.');
            }
        } else {
            throw new ScrapeException;
        }
       
        $xs = Selector\XPath::loadHTML($response->getBody(true));
        $result = array();

        $info      = $xs->select('//div[@class="newspaper_head"]');
        $avatar    = $info->select('//img[@class="avatar"]/@src')->extract();
        $url       = explode("/",$info->select('div[@class="info"]/h1/a[1]/@href')->extract());
        $url       = $url[3];
        $meta      = $xs->select('/*/head/meta[@name="description"]/@content')->extract();
        $meta1     = strpos($meta,'has ');
        $meta2     = strpos($meta,' articles');
        $pages     = explode("_", $xs->select('//ul[@class="pager"]/li[7]/a/@rel')->extract());
        $pages     = $pages[1];
        $page      = 1;
        if ($pageLimit !== null) {
            if ($pages > $pageLimit) {
                $pages = $pageLimit;
            }
        }
        /**
         * BASIC DATA
         */
        $result['director']['name'] = $info->select('//li/a/@title')->extract();
        $result['director']['id']   = (int)substr($url, strrpos($url, '/')+1);
        $result['name']             = $info->select('//h1/a/@title')->extract();
        $result['avatar']           = str_replace('55x55','100x100',$avatar);
        $result['country']          = $info->select('div[1]/a[1]/img[2]/@title')->extract();
        $result['subscribers']      = (int)$info->select('div[@class="actions"]')->extract();
        $result['article_count']         = (int)substr($meta,($meta1+3),($meta2 - $meta1 -3));
        if($result['avatar'] == '/images/default_avatars/Newspapers/default_100x100.gif'){
            $result['avatar'] = NULL;
        }

        while ($page <= $pages) {            
            $response = $this->getClient()->get('newspaper/'.$url.'/'.$page)->send();
            $xs = Selector\XPath::loadHTML($response->getBody(true));
            $articles  = $xs->select('//div[@class="post"]');
            if ($articles->hasResults()) {
                foreach ($articles as $art) {
                    $title = $art->select('div[2]/h2/a')->extract();
                    $artUrl = 'http://www.erepublik.com'.$art->select('div[2]/h2/a/@href')->extract();
                    $votes = $art->select('div[1]/div[1]/strong')->extract();
                    $comments = $art->select('div[2]/div[1]/a[1]')->extract();
                    $date = $art->select('div[2]/div[1]/em')->extract();
                    $category = $art->select('div[2]/div[1]/a[3]')->extract();
                    $result['articles'][] = array(
                        'title' => $title,
                        'url' => $artUrl,
                        'votes' => $votes,
                        'comments' => $comments,
                        'date' => $date,
                        'category' => $category
                    );
                }
            }
            $page++;
        }
        
        return $result;
    }
}
