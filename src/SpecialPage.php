<?php
namespace MediaWiki\Extension\WantedAnchors;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\ILoadBalancer;

class SpecialPage extends \SpecialPage {
    function __construct() {
        parent::__construct('wantedAnchors');
    }

    function execute($subPage) {
        $request = $this->getRequest();
        $output = $this->getOutput();
        $this->setHeaders();

        $param = $request->getText('param');

        $db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef(ILoadBalancer::DB_PRIMARY);
        $resultWrapper = $db->select(
            [
                'pagelinks',
                'page_src' => 'page',
                'slots',
                'content',
            ],
            [
                'pl_from',
                'page_title',
                'slot_revision_id',
                'slot_content_id',
                'content_address',
            ],
            'page_src.page_namespace = 0 AND pl_namespace = 0',
            __METHOD__,
            [
                'DISTINCT',
            ],
            [
                'page_src' => [
                    'LEFT JOIN',
                    'page_src.page_id = pagelinks.pl_from'
                ],
                'slots' => [
                    'LEFT JOIN',
                    'slots.slot_revision_id = page_src.page_latest'
                ],
                'content' => [
                    'LEFT JOIN',
                    'content.content_id = slots.slot_content_id'
                ],
            ]
        );
        $result = [];
        $queryTexts = [];
        foreach ($resultWrapper as $row) {
            $result[$row->page_title] = [
                'rev_id' => $row->slot_revision_id,
                'content_id' => $row->slot_content_id,
                'content_address' => $row->content_address,
            ];
            $contentAddressMatches = [];
            if ($row->content_address && preg_match('/^tt:(\\d+)$/', $row->content_address, $contentAddressMatches)) {
                $queryTexts[] = $contentAddressMatches[1];
            }
        }
        $queryTexts = array_unique($queryTexts);

        $resultWrapper = $db->select(
            'text',
            ['old_id', 'old_text'],
            'old_id IN (' . join(', ', $queryTexts) . ')',
            __METHOD__
        );
        $texts = [];
        foreach ($resultWrapper as $row) {
            $texts[$row->old_id] = $row->old_text;
        }

        $linkedFrom = [];
        foreach ($result as $pageTitle => &$data) {
            $contentAddressMatches = [];
            if ($data['content_address'] && preg_match('/^tt:(\\d+)$/', $data['content_address'], $contentAddressMatches)) {
                $data['content'] = $texts[$contentAddressMatches[1]];
                $hashLinksWithText = [];
                $hashLinksWithoutText = [];
                $regexLinksWithText = '/\[\[([\w\d\s\-,&\/\(\)]*#[\w\d\s\-,&\/\(\)]+)|(?!]])\]\]/m';
                $regexLinksWithoutText = '/\[\[([\w\d\s\-,&\/\(\)]*#[\w\d\s\-,&\/\(\)]+)\]\]/m';
                preg_match_all($regexLinksWithText, $data['content'], $hashLinksWithText);
                $hashLinksWithText = array_merge(...$hashLinksWithText); // flatten
                preg_match_all($regexLinksWithoutText, $data['content'], $hashLinksWithoutText);
                $hashLinksWithoutText = array_merge(...$hashLinksWithoutText); // flatten
                $hashLinks = array_unique(array_merge($hashLinksWithText, $hashLinksWithoutText));
                $hashLinks = array_filter($hashLinks, function($stuff) {
                    return !str_starts_with($stuff, '[[');
                });
                $hashLinks = array_map(function($hashLink) use ($pageTitle) {
                    if (str_starts_with($hashLink, '#')) {
                        return str_replace('_', ' ', $pageTitle) . $hashLink;
                    } else {
                        return $hashLink;
                    }
                }, $hashLinks);
                $data['hashLinks'] = array_values($hashLinks);

                foreach ($data['hashLinks'] as $hashLink) {
                    if (!array_key_exists($hashLink, $linkedFrom)) {
                        $linkedFrom[$hashLink] = [];
                    }
                    $linkedFrom[$hashLink][] = $pageTitle;
                }

                unset($data['content']);
            }
        }

        $reparseTargetPages = [];
        foreach ($linkedFrom as $hashLink => &$linksFrom) {
            $linksFrom = array_unique($linksFrom);

            $hashPos = strpos($hashLink, '#');
            $targetPageName = substr($hashLink, 0, $hashPos);

            if (!array_key_exists($targetPageName, $reparseTargetPages)) {
                $reparseTargetPages[$targetPageName] = [];
            }
            $reparseTargetPages[$targetPageName][$hashLink] = $linksFrom;
        }

        //$output->addWikiTextAsContent('<pre>' . json_encode($result, JSON_PRETTY_PRINT) . '</pre>');
        //$output->addWikiTextAsContent('<pre>' . json_encode($linkedFrom, JSON_PRETTY_PRINT) . '</pre>');
        //$output->addWikiTextAsContent('<pre>' . json_encode($reparseTargetPages, JSON_PRETTY_PRINT) . '</pre>');
        $output->addWikiTextAsContent($this->msg('wantedanchors-intro')->rawParams(count($reparseTargetPages))->escaped());
        $reparseTargetPagesWikiText = '';
        foreach ($reparseTargetPages as $targetPageName => $hashLinks) {
            $reparseTargetPagesWikiText .= "* [[$targetPageName]], "
                . $this->msg('wantedanchors-targetpage-hashlinks')->rawParams(count($hashLinks))->escaped()
                . PHP_EOL;

            foreach ($hashLinks as $hashLink => $linkedFrom) {
                $reparseTargetPagesWikiText .= "** [[$hashLink]], "
                    . $this->msg('wantedanchors-hashlink-origins')->rawParams(count($linkedFrom))->escaped()
                    . PHP_EOL;

                foreach ($linkedFrom as $linkFrom) {
                    $linkFrom = str_replace('_', ' ', $linkFrom);
                    $reparseTargetPagesWikiText .= "*** [[$linkFrom]]" . PHP_EOL;
                }
            }
        }
        $output->addWikiTextAsContent($reparseTargetPagesWikiText);
    }
}