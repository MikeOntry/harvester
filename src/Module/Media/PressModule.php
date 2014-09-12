<?php
namespace Erpk\Harvester\Module\Media;

use Erpk\Harvester\Exception\ScrapeException;
use Erpk\Harvester\Module\Module;
use Erpk\Harvester\Client\Selector;
use Erpk\Harvester\Filter;
use Erpk\Common\DateTime;
use Erpk\Common\EntityManager;

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

    public static function parseDate($string)
    {
        $date = str_replace(',', '', trim($string));
        $date = explode(' ', $date);
        if ($date[2] == 'ago') {
            return new DateTime(implode(' ', $date));
        }
        $time = explode(':', $date[2]);
        $date = DateTime::createFromDay($date[1]);
        $date->setTime((int)$time[0], (int)$time[1], 0);
        return $date;
    }
    public static function parseArticleComments($xs)
    {
        
        $comments = $xs->findAll('div[contains(concat(" ", normalize-space(@class), " "), " comment-holder ")]');
        $list = $comments->map(function ($node) use ($xs) {
            $class = $node->find('@class')->extract();
            if (preg_match('/indent-level-(\d+)/', $class, $level) > 0) {
                $level = (int)$level[1];
            } else {
                throw new ScrapeException();
            }

            $left = $node->find('div[1]');
            $right = $node->find('div[2]/div[1]');

            if ($level > 0) {
                $nameholder = $right->find('span[1]/a[1]');
                $avatarholder = $right->find('div[1]');
            } else {
                $nameholder = $left->find('a[1]');
                $avatarholder = $left->find('div[1]');
            }

            $id = (int)str_replace('comment', '', $node->find('@id')->extract());
            try {
                $right->find('i[@class="cmnt-deleted"][1]');
                $deleted = true;
                $votes   = null;
                $date    = null;
            } catch (\Exception $e) {
                $deleted = false;
                $votes   = (int)trim($right->find('ul[1]/li/a[@id="nr_vote_'.$id.'"]')->extract());
                if ($level > 0) {
                    $date = $right->find('ul[1]/li/span[@class="article_comment_posted_at"]');
                } else {
                    $date = $left->find('span[1]');
                }
                $date = self::parseDate($date->extract());
            }

            switch ($level) {
                case 0:
                    $content = $right;
                    $toRemove = [
                        'a[@class="cmnt-report-link"][1]',
                        'div[@class="list_voters"][1]',
                        'ul[@class="reply_links"][1]',
                        'form[1]',
                        'div[@style="clear: both"][1]'
                    ];
                    break;
                default:
                    $toRemove = ['a[@class="nameholder"]'];
                    $content = $right->find('span[@class="comment-text"]');
                    break;
            }

            $contentRaw = $content->getDOMNode();
            foreach ($toRemove as $path) {
                foreach ($content->findAll($path) as $result) {
                    $contentRaw->removeChild($result->getDOMNode());
                }
            }

            return [
                'id' => $id,
                'level' => $level,
                'parent_id' => null,
                'date' => $date,
                'votes' => $votes,
                'author' => [
                    'id' => (int)explode('/', $nameholder->find('@href')->extract())[4],
                    'name' => $nameholder->find('@title')->extract(),
                    'avatar' => $avatarholder->find('a[1]/img[1]/@src')->extract()
                ],
                'content_html' => trim($content->innerHTML())
            ];
        });
        
        // determine parent_ids for every comment
        $levels = [];
        $before = null;
        foreach ($list as &$after) {
            if ($before === null) {
                $before = $after;
                continue;
            }
            //
            if ($after['level'] > $before['level']) {
                $levels[] = $before['id'];
            } else if ($after['level'] < $before['level']) {
                for ($i = $after['level']; $i > $before['level']; $i--) {
                    array_pop($levels);
                }
            }

            if (!empty($levels)) {
                $after['parent_id'] = end($levels);
            }
            //
            $before = $after;
        }

        return $list;
    }

    public function getArticle($id)
    {
        $this->getClient()->checkLogin();
        $location = $this->getClient()->get('article/'.$id.'/1/20')->send()->getLocation();
        $response = $this->getClient()->get($location)->send();
        $xs = $response->xpath();

        $head = $xs->find('//div[@class="newspaper_head"]');
        $date = $xs->find('//div[@class="post_details"]/em[@class="date"]');

        $em = EntityManager::getInstance();
        $countries = $em->getRepository('Erpk\Common\Entity\Country');

        $article = [
            'id'  => $id,
            'url' => 'http://www.erepublik.com'.$location,
            'date' => self::parseDate($date->extract()),
            'title' => $xs->find('//div[@class="post_content"][1]/h2[1]/a[1]')->extract(),
            'votes' => (int)trim($xs->find('//strong[@class="numberOfVotes_'.$id.'"][1]')->extract()),
            'category' => $xs->find('//a[@class="category_name"][1]/@title')->extract(),
            'newspaper' => [
                'id' => (int)$xs->find('//input[@id="newspaper_id"]/@value')->extract(),
                'owner' => [
                    'id' => (int)explode('/', $head->find('div[2]/ul[1]/li[1]/a[1]/@href')->extract())[4],
                    'name' => $head->find('div[2]/ul[1]/li[1]/a[1]/@title')->extract()
                ],
                'name' => $head->find('div[1]/a[1]/@title')->extract(),
                'country' => $countries->findOneByName($xs->find('//a[@class="newspaper_country"][1]/img/@title')->extract()),
                'avatar' => $head->find('div[1]/a[1]/img[@class="avatar"]/@src')->extract(),
                'subscribers' => (int)$xs->find('//em[@class="subscribers"]')->extract()
            ],
            'content_html' => trim($xs->find('//div[@class="full_content"]')->innerHTML()),
            'comments' => self::parseArticleComments($xs->find('//div[@id="loadMoreComments"]'))
        ];

        $pagesTotal = 1;
        try {
            $moreComments = $xs->find('//a[@class="load-more-comments"]/@onclick');
            if (preg_match('/commentCurrentPage >= (\d+)/', $moreComments, $pages) > 0) {
                $pagesTotal = (int)$pages[1];
            }
        } catch (\Exception $e) {
        }

        unset($xs);

        for ($p = 2; $p <= $pagesTotal; $p++) {
            $request = $this->getClient()->post('main/article-comment/loadMoreComments/');
            $request->addPostFields([
                'articleId' => $id,
                'page'      => $p,
                '_token'    => $this->getSession()->getToken()
            ]);
            $xs = $request->send()->xpath();
            foreach (self::parseArticleComments($xs->find('//body')) as $comment) {
                $article['comments'][] = $comment;
            }
        }
        
        return $article;
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
