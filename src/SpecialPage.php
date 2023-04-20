<?php
/**
 * Copyright 2023 Artūras Šelechov-Balčiūnas
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace MediaWiki\Extension\WantedAnchors;
use MediaWiki\MediaWikiServices;
use Wikimedia\Rdbms\ILoadBalancer;

class SpecialPage extends \SpecialPage {
    function __construct() {
        parent::__construct('wantedAnchors');
    }

    function execute($subPage) {
        $startTimes = [];
        $endTimes = [];
        $startTimes['total'] = hrtime(true);

        $output = $this->getOutput();
        $this->setHeaders();
        $db = MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnectionRef(ILoadBalancer::DB_PRIMARY);

        // Locate all pages within the wiki which link to other pages.
        $startTimes['locate-origin-pages'] = hrtime(true);
        $originPagesWrapper = $db->select(
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
        $originPages = [];
        // Prepare to extract wikitext from each located origin page.
        $queryTexts = [];
        foreach ($originPagesWrapper as $row) {
            $originPages[$row->page_title] = [
                'rev_id' => $row->slot_revision_id,
                'content_id' => $row->slot_content_id,
                'content_address' => $row->content_address,
            ];
            $contentAddressMatches = [];
            if ($row->content_address && preg_match('/^tt:(\\d+)$/', $row->content_address, $contentAddressMatches)) {
                $queryTexts[] = $contentAddressMatches[1];
            }
        }
        $endTimes['locate-origin-pages'] = hrtime(true);

        // Extract wikitext for each origin page.
        $queryTexts = array_unique($queryTexts);
        $startTimes['load-content-origin-pages'] = hrtime(true);
        $textsWrapper = $db->select(
            'text',
            ['old_id', 'old_text'],
            'old_id IN (' . join(', ', $queryTexts) . ')',
            __METHOD__
        );
        $texts = [];
        foreach ($textsWrapper as $row) {
            $texts[$row->old_id] = $row->old_text;
        }
        $endTimes['load-content-origin-pages'] = hrtime(true);

        // Extract hashlinks from each origin page (parse its wikitext).
        $startTimes['extract-hashlinks-from-origin-pages'] = hrtime(true);
        $linkedFrom = [];
        foreach ($originPages as $pageTitle => &$data) {
            $contentAddressMatches = [];
            if ($data['content_address'] && preg_match('/^tt:(\\d+)$/', $data['content_address'], $contentAddressMatches)) {
                $data['content'] = $texts[$contentAddressMatches[1]];
                $hashLinksWithText = [];
                $hashLinksWithoutText = [];
                $regexLinksWithText =    '/\[\[([\w\d\s\-,&\/\(\)]*#[\w\d\s\-,&\'\/\(\)]+)|(?!]])\]\]/m';
                $regexLinksWithoutText = '/\[\[([\w\d\s\-,&\/\(\)]*#[\w\d\s\-,&\'\/\(\)]+)\]\]/m';
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
        $endTimes['extract-hashlinks-from-origin-pages'] = hrtime(true);

        // Figure out where the extracted hashlinks target to (which pages they refer to).
        // Those target pages must be examined later for existence of required sections/anchors.
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

        // Parse each target page, extract existing anchors.
        $startTimes['parse-target-pages'] = hrtime(true);
        $foundAnchorsInTargetPages = [];
        $oldLibXmlUseInternalErrors = libxml_use_internal_errors(true);
        foreach ($reparseTargetPages as $targetPageName => $_) {
            try {
                $parseRequest = new \FauxRequest([
                    'action' => 'parse',
                    'page' => $targetPageName,
                    'prop' => 'text',
                ]);
                $apiParseRequest = new \ApiMain($parseRequest);
                $apiParseRequest->execute();
                $foundAnchorsInTargetPages[$targetPageName] = $apiParseRequest->getResult()->getResultData()['parse']['text'];

                $dom = new \DOMDocument();
                $dom->loadHTML($foundAnchorsInTargetPages[$targetPageName]);
                $xpath = new \DOMXPath($dom);
                $anchorElems = $xpath->query('//*[@id]');
                $anchors = [];
                foreach ($anchorElems as $anchorElem) {
                    $anchors[] = str_replace('_', ' ', $anchorElem->getAttribute('id'));
                }
                $foundAnchorsInTargetPages[$targetPageName] = $anchors;
            } catch (\Exception $e) {
                // nop
            }
        }
        libxml_use_internal_errors($oldLibXmlUseInternalErrors);
        $endTimes['parse-target-pages'] = hrtime(true);

        // Collect all anchors found among target pages into one bucket.
        $startTimes['collect-all-anchors'] = hrtime(true);
        $foundAnchors = [];
        foreach ($foundAnchorsInTargetPages as $targetPageName => $targetPageAnchors) {
            foreach ($targetPageAnchors as $targetPageAnchor) {
                $foundAnchors[] = "$targetPageName#$targetPageAnchor";
            }
        }
        $foundAnchors = array_unique($foundAnchors);
        $endTimes['collect-all-anchors'] = hrtime(true);

        // Copy only records with broken hashlinks (exclude hashlinks pointing to existing anchors).
        $startTimes['prepare-report'] = hrtime(true);
        $brokenHashLinksReport = [];
        foreach ($reparseTargetPages as $targetPageName => $hashLinks) {
            foreach ($hashLinks as $hashLink => $linkedFrom) {
                if (in_array($hashLink, $foundAnchors)) {
                    continue;
                }

                if (!array_key_exists($targetPageName, $brokenHashLinksReport)) {
                    $brokenHashLinksReport[$targetPageName] = [];
                }

                $brokenHashLinksReport[$targetPageName][$hashLink] = $linkedFrom;
            }
        }
        $endTimes['prepare-report'] = hrtime(true);

        // Wiki output to the special page.
        $output->addWikiTextAsContent($this->msg('wantedanchors-intro')->rawParams(count($brokenHashLinksReport))->escaped());
        $brokenHashLinksReportWikiText = '';
        foreach ($brokenHashLinksReport as $targetPageName => $hashLinks) {
            $brokenHashLinksReportWikiText .= "* [[$targetPageName]], "
                . $this->msg('wantedanchors-targetpage-hashlinks')->rawParams(count($hashLinks))->escaped()
                . PHP_EOL;

            foreach ($hashLinks as $hashLink => $linkedFrom) {
                $brokenHashLinksReportWikiText .= "** [[$hashLink]], "
                    . $this->msg('wantedanchors-hashlink-origins')->rawParams(count($linkedFrom))->escaped()
                    . PHP_EOL;

                foreach ($linkedFrom as $linkFrom) {
                    $linkFrom = str_replace('_', ' ', $linkFrom);
                    $brokenHashLinksReportWikiText .= "*** [[$linkFrom]]" . PHP_EOL;
                }
            }
        }
        $output->addWikiTextAsContent($brokenHashLinksReportWikiText);

        $endTimes['total'] = hrtime(true);

        // Output performance stats.
        $perfWikiText = '{| class="wikitable"' . PHP_EOL;
        $perfWikiText .= '|+ ' . $this->msg('wantedanchors-perf-stats-caption')->escaped() . PHP_EOL;
        $perfWikiText .= '! ' . $this->msg('wantedanchors-perf-stats-phase')->escaped()
            . ' !! ' . $this->msg('wantedanchors-perf-stats-time')->escaped()
            . PHP_EOL;
        $statsKeys = array_intersect(array_keys($startTimes), array_keys($endTimes));
        foreach ($statsKeys as $key) {
            if ($key == 'total') {
                continue;
            }

            $perfWikiText .= '|-' . PHP_EOL;
            $perfWikiText .= '| ' . $this->msg("wantedanchors-perf-stats-$key")->escaped()
                . ' || ' . $this->formatTime($endTimes[$key] - $startTimes[$key])
                . PHP_EOL;
        }
        $perfWikiText .= '|-' . PHP_EOL;
        $perfWikiText .= '| ' . $this->msg('wantedanchors-perf-stats-total')->escaped()
            . ' || ' . $this->formatTime($endTimes['total'] - $startTimes['total'])
            . PHP_EOL;
        $perfWikiText .= '|}';
        $output->addWikiTextAsContent($perfWikiText);
    }

    private function formatTime($nanoseconds): string {
        $seconds = $nanoseconds / 1e9;
        return sprintf("%.3f", $seconds) . ' ' . $this->msg('wantedanchors-units-sec')->escaped();
    }
}