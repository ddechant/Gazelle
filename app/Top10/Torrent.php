<?php

namespace Gazelle\Top10;

class Torrent extends \Gazelle\Base {

    protected array $formats;
    protected \Gazelle\User $viewer;

    public function __construct (array $formats, \Gazelle\User $viewer) {
        $this->formats = $formats;
        $this->viewer = $viewer;
    }

    public function getTopTorrents($getParameters, $details = 'all', $limit = 10) {
        $cacheKey = 'top10_v2_' . $details . '_' . md5(implode('', $getParameters)) . '_' . $limit;
        $topTorrents = self::$cache->get_value($cacheKey);

        if ($topTorrents !== false) {
            return $topTorrents;
        }
        if (!self::$cache->get_query_lock($cacheKey)) {
            return false;
        }

        $where = [];
        $anyTags = isset($getParameters['anyall']) && $getParameters['anyall'] == 'any';
        if (isset($getParameters['format'])) {
            $where[] = $this->formatWhere($getParameters['format']);
        }
        if (isset($getParameters['tags'])) {
            $where[] = $this->tagWhere($getParameters['tags'], $anyTags);
        }

        $where[] = $this->freeleechWhere($getParameters);
        $where[] = $this->detailsWhere($details);

        $where[] = ["parameters" => null, "where" => "tls.Seeders > 0"];

        $whereFilter = function($value) {
            return $value["where"] ?? null;
        };

        $parameterFilter = function($value) {
            return $value["parameters"] ?? null;
        };

        $filteredWhere = array_filter(array_map($whereFilter, $where));
        $parameters = $this->flatten(array_filter(array_map($parameterFilter, $where)));

        $innerQuery = '';
        $joinParameters = [];

        if (!empty($getParameters['excluded_artists'])) {
            [$clause, $artists] = $this->excludedArtistClause($getParameters['excluded_artists']);
            $innerQuery .= $clause;
            $joinParameters[] = $artists;
            $filteredWhere[] = "ta.ArtistCount IS NULL";
        }

        if (count($joinParameters)) {
            $joinParameters = $this->flatten($joinParameters);
            $parameters = array_merge($joinParameters, $parameters);
        }

        $innerQuery .= " WHERE " . implode(" AND ", $filteredWhere);
        $innerQuery = $innerQuery . (isset($getParameters['groups']) && $getParameters['groups'] == 'show' ? ' GROUP BY g.ID ' : '');
        $orderBy = 'ORDER BY ' . $this->orderBy($details) . ' DESC';

        $query = sprintf($this->baseQuery,
            $innerQuery,
            ($getParameters['groups'] ?? 'hide') == 'show' ? 'g.ID' : 't.ID, g.ID',
            $this->orderBy($details) . ' DESC',
            $limit
        );

        self::$db->prepared_query($query, ...$parameters);
        $topTorrents = self::$db->to_array();

        self::$cache->cache_value($cacheKey, $topTorrents, 3600 * 6);
        self::$cache->clear_query_lock($cacheKey);
        return $topTorrents;
    }

    public function showFreeleechTorrents($freeleechParameters): bool {
        if (isset($freeleechParameters)) {
            return $freeleechParameters == 'hide';
        }
        return (bool)$this->viewer->option('DisableFreeTorrentTop10');
    }

    private function orderBy($details) {
        switch($details) {
            case 'snatched':
                return 'tls.Snatched';
                break;
            case 'seeded':
                return 'tls.Seeders';
                break;
            case 'data':
                return 'Data';
                break;
            default:
                return '(tls.Seeders + tls.Leechers)';
                break;
        }
    }

    private function detailsWhere($detailsParameters) {
        switch($detailsParameters) {
            case 'day':
                return ["parameters" => null, "where" => "t.Time > now() - INTERVAL 1 DAY"];
                break;
            case 'week':
                return ["parameters" => null, "where" => "t.Time > now() - INTERVAL 1 WEEK"];
                break;
            case 'month':
                return ["parameters" => null, "where" => "t.Time > now() - INTERVAL 1 MONTH"];
                break;
            case 'year':
                return ["parameters" => null, "where" => "t.Time > now() - INTERVAL 1 YEAR"];
                break;
            default:
                return [];
                break;
        }
    }

    private function excludedArtistClause($artistParameter) {
        if (!empty($artistParameter)) {
            $artists = preg_split('/\r\n|\r|\n/', trim($artistParameter));

            $artistPrepare = function($artist) { return trim($artist); };
            $artists = array_map($artistPrepare, $artists);

            $sql = "
            LEFT JOIN (
                SELECT COUNT(*) AS ArtistCount, ta.GroupID
                FROM torrents_artists AS ta
                INNER JOIN artists_alias AS aa ON (ta.AliasID = aa.AliasID)
                WHERE ta.Importance != '2' AND aa.Name IN (" . placeholders($artists) . ")
                GROUP BY ta.GroupID
            ) AS ta ON (g.ID = ta.GroupID)";
            return [$sql, $artists];
        }

        return ['', []];
    }

    private function formatWhere($formatParameters) {
        if (in_array($formatParameters, $this->formats)) {
            return ["parameters" => $formatParameters, "where" => "t.Format = ?"];
        }
    }

    private function freeleechWhere($getParameters) {
        $disableFreeTorrentTop10 = $this->viewer->option('DisableFreeTorrentTop10') ?? false;

        if (isset($getParameters['freeleech'])) {
            $disableFreeTorrentTop10 = ($getParameters['freeleech'] == 'hide' ? 1 : 0);
        }

        if ($disableFreeTorrentTop10) {
            return ["parameters" => null, "where" => "t.FreeTorrent = '0'"];
        }

        return [];
    }

    private function tagWhere($getParameters, $any = false) {
        if (!empty($getParameters)) {
            $tags = explode(',', trim($getParameters));
            $replace = function($tag) { return preg_replace('/[^a-z0-9.]/', '', $tag); };
            $tags = array_map($replace, $tags);
            $tags = array_filter($tags);

            // This is to make the prepared query work.

            $where = implode(' OR ', array_fill(0,  count($tags), "t.Name = ?"));
            $clause = "
        g.ID IN (
            SELECT tt.GroupID
            FROM torrents_tags tt
            INNER JOIN tags t ON (t.ID = tt.TagID)
            WHERE $where
            GROUP BY tt.GroupID
            HAVING count(*) >= ?
        )";
            $tags[] = $any ? 1 : count($tags);
            return ['parameters' => $tags, 'where' => $clause];
        }

        return [];
    }

    private function flatten(array $array) {
        $return = [];
        array_walk_recursive($array, function($a) use (&$return) { $return[] = $a; });
        return $return;
    }

    private $baseQuery = "
        SELECT
            t.ID,
            g.ID,
            ((t.Size * tls.Snatched) + (t.Size * 0.5 * tls.Leechers)) AS Data
        FROM torrents AS t
        INNER JOIN torrents_leech_stats tls ON (tls.TorrentID = t.ID)
        INNER JOIN torrents_group AS g ON (g.ID = t.GroupID)
        %s
        GROUP BY %s
        ORDER BY %s
        LIMIT %s";
}
