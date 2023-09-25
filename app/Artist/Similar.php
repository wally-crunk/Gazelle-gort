<?php

namespace Gazelle\Artist;

class Similar extends \Gazelle\Base {
    /**
     * Two similar artists share the same similar id. This id is
     * generated by inserting a row into artists_similar_scores.
     * This id is then used to store the two artists in the
     * artists_similar table. To make it trivial to find the pair
     * regardless of which artist point of view is used, two
     * records are stored in that table.
     *
     * When artist id 29 is similar to artist id 72, a row is
     * inserted into artists_similar_scores, giving a similar id
     * of e.g. 6. In the artists_similar table, the tuples
     * (6, 29) and (6, 72) are stored.
     *
     * To avoid confusion between the id of similarity and the
     * id of the similar artist, the latter is always referred to
     * as "other" in the code.
     *
     * This is why, when looking for artists similar to id 29,
     * the join is equality on SimilarID and inequality on ArtistID.
     */
    protected const CACHE_KEY    = 'artsim_%d';
    protected const POSITION_KEY = 'artpos_%d';

    protected array|null $info;

    public function __construct(
        protected \Gazelle\Artist $artist,
    ) {}

    public function flush(): static {
        self::$cache->delete_multi([
            sprintf(self::CACHE_KEY, $this->id()),
            sprintf(self::POSITION_KEY, $this->id()),
        ]);
        unset($this->info);
        return $this;
    }

    public function artist(): \Gazelle\Artist {
        return $this->artist;
    }

    public function id(): int {
        return $this->artist->id();
    }

    public function info(): array {
        if (isset($this->info)) {
            return $this->info;
        }
        $key = sprintf(self::CACHE_KEY, $this->id());
        $info = self::$cache->get_value($key);
        if ($info === false) {
            self::$db->prepared_query("
                SELECT s2.ArtistID AS artist_id,
                    a.Name         AS name,
                    ass.Score      AS score,
                    ass.SimilarID  AS similar_id
                FROM artists_similar AS s1
                INNER JOIN artists_similar AS s2 ON (s1.SimilarID = s2.SimilarID AND s1.ArtistID != s2.ArtistID)
                INNER JOIN artists_similar_scores AS ass ON (ass.SimilarID = s1.SimilarID)
                INNER JOIN artists_group AS a ON (a.ArtistID = s2.ArtistID)
                WHERE s1.ArtistID = ?
                ORDER BY ass.Score DESC, a.Name
                LIMIT 30
                ", $this->id()
            );
            $info = self::$db->to_array(false, MYSQLI_ASSOC, false);
        }
        $this->info = $info;
        return $this->info;
    }

    public function findSimilarId(\Gazelle\Artist $other): int {
        $artist = array_values(array_filter($this->info(), fn($s) => $s['artist_id'] == $other->id()));
        return $artist[0]['similar_id'] ?? 0;
    }

    public function addSimilar(\Gazelle\Artist $other, \Gazelle\User $user, \Gazelle\Log $logger): int {
        $thisId = $this->id();
        $otherId = $other->id();
        self::$db->begin_transaction();
        $findId = $this->findSimilarId($other);
        if ($findId) {
            // The similar artists field already exists, if the person adding
            // has not added or voted before, consider it an upvote for the
            // existing similarity
            self::$db->prepared_query("
                UPDATE artists_similar_scores SET
                    Score = Score + 200
                WHERE SimilarID = ?
                    AND NOT EXISTS (
                        SELECT 1 FROM artists_similar_votes WHERE SimilarID = ? AND UserID = ?
                    )
                ", $findId, $findId, $user->id()
            );
        } else {
            // No, it doesn't exist - create it
            self::$db->prepared_query("
                INSERT INTO artists_similar_scores (Score) VALUES (200)
            ");
            $findId = self::$db->inserted_id();
            self::$db->prepared_query("
                INSERT INTO artists_similar
                       (ArtistID, SimilarID)
                VALUES (?, ?), (?, ?)
                ", $thisId, $findId, $otherId, $findId
            );
            $logger->general("User {$user->label()} set artist {$this->artist()->label()} similar to artist {$other->label()}");
        }
        self::$db->prepared_query("
            INSERT IGNORE INTO artists_similar_votes
                   (SimilarID, UserID, way)
            VALUES (?,         ?,      'up')
            ", $findId, $user->id()
        );
        $affected = self::$db->affected_rows();
        self::$db->commit();
        $this->flush();
        $other->flush();
        return $affected;
    }

    public function voteSimilar(\Gazelle\User $user, \Gazelle\Artist $other, bool $upvote): bool {
        $similarId = $this->findSimilarId($other);
        if (!$similarId) {
            return false;
        }

        // if the vote already exists in this direction: do nothing
        $vote = $upvote ? 'up' : 'down';
        if ((bool)self::$db->scalar("
            SELECT 1
            FROM artists_similar_votes
            WHERE SimilarID = ?
                AND UserID = ?
                AND Way = ?
            ", $similarId, $user->id(), $vote
        )) {
            return false;
        }

        // if the new vote is in the opposite direction of the old one,
        // remove the previous vote
        $opposite = !$upvote ? 'up' : 'down';
        if ((bool)self::$db->scalar("
            SELECT 1
            FROM artists_similar_votes
            WHERE SimilarID = ?
                AND UserID = ?
                AND Way = ?
            ", $similarId, $user->id(), $opposite
        )) {
            self::$db->begin_transaction();
            self::$db->prepared_query("
                UPDATE artists_similar_scores SET
                    Score = Score + ?
                WHERE SimilarID = ?
                ", $upvote ? -100 : 100, $similarId
            );
            self::$db->prepared_query("
                DELETE FROM artists_similar_votes
                WHERE SimilarID = ?
                    AND UserID  = ?
                    AND Way     = ?
                ", $similarId, $user->id(), $opposite
            );
            self::$db->commit();

        } else {
            // there is no vote: record it
            self::$db->begin_transaction();
            self::$db->prepared_query("
                UPDATE artists_similar_scores SET
                    Score = Score + ?
                WHERE SimilarID = ?
                ", $upvote ? 100 : -100, $similarId
            );
            self::$db->prepared_query("
                INSERT INTO artists_similar_votes
                       (SimilarID, UserID, Way)
                VALUES (?,         ?,      ?)
                ", $similarId, $user->id(), $vote
            );
            self::$db->commit();
        }

        $this->flush();
        $other->flush();
        return true;
    }

    public function removeSimilar(\Gazelle\Artist $other, \Gazelle\User $user, \Gazelle\Log $logger): bool {
        $similarId = $this->findSimilarId($other);
        if (!$similarId) {
            return false;
        }
        self::$db->prepared_query("
            DELETE FROM artists_similar_scores WHERE SimilarID = ?
            ", $similarId
        );
        $logger->general("User {$user->label()} removed artist {$this->artist()->label()} similar to artist {$other->label()}");
        $this->flush();
        $other->flush();
        return true;
    }

    public function similarGraph(int $width, int $height): array {
        // find the similar artists of this one
        self::$db->prepared_query("
            SELECT s2.ArtistID       AS artist_id,
                a.Name               AS artist_name,
                ass.Score            AS score,
                count(asv.SimilarID) AS votes
            FROM artists_similar s1
            INNER join artists_similar s2 ON (s1.SimilarID = s2.SimilarID AND s1.ArtistID != s2.ArtistID)
            INNER JOIN artists_group AS a ON (a.ArtistID = s2.ArtistID)
            INNER JOIN artists_similar_scores ass ON (ass.SimilarID = s1.SimilarID)
            INNER JOIN artists_similar_votes asv ON (asv.SimilarID = s1.SimilarID)
            WHERE s1.ArtistID = ?
            GROUP BY s1.SimilarID
            ORDER BY score DESC,
                votes DESC
            LIMIT 30
            ", $this->id()
        );
        $artistIds = self::$db->collect('artist_id') ?: [0];
        $similar   = self::$db->to_array('artist_id', MYSQLI_ASSOC, false);
        if (!$similar) {
            return [];
        }
        $nrSimilar = count($similar);

        // of these similar artists, see if any are similar to each other
        self::$db->prepared_query("
            SELECT s1.artistid AS source,
                group_concat(s2.artistid) AS target
            FROM artists_similar s1
            INNER JOIN artists_similar s2 ON (s1.similarid = s2.similarid and s1.artistid != s2.artistid)
            WHERE s1.artistid in (" . placeholders($artistIds) . ")
                AND s2.artistid in (" . placeholders($artistIds) . ")
            GROUP BY s1.artistid
            ", ...array_merge($artistIds, $artistIds)
        );
        $relation = self::$db->to_array('source', MYSQLI_ASSOC, false);

        // calculate some minimax stuff to figure out line lengths
        $max = 0;
        $min = null;
        $totalScore = 0;
        foreach ($similar as &$s) {
            $s['related'] = [];
            $s['nrRelated'] = 0;
            $max = max($max, $s['score']);
            if (is_null($min)) {
                $min = $s['score'];
            } else {
                $min = min($min, $s['score']);
            }
            $totalScore += $s['score'];
        }
        unset($s);

        // Use the golden ratio formula to generate the angles where the
        // artists will be placed (to avoid drawing a line through the
        // origin for a relation when there are an even number of artists).
        // Sort the results because a) the order will be vaguely chaotic,
        // and b) we have a guarantee that two adjacent angles will be
        // at the beginning and end of the array (as long as we alternate
        // between shifting and popping the array).
        $layout = [];
        $angle = fmod($this->id(), 2 * M_PI);
        $golden = M_PI * (3 - sqrt(5));
        foreach (range(0, $nrSimilar-1) as $r) {
            $layout[] = $angle;
            $angle = fmod($angle + $golden, 2 * M_PI);
        }
        sort($layout);

        // Thread all the similar artists with their related artists
        // and sort those with the most relations first.
        foreach ($relation as $source => $targetList) {
            $t = explode(',', $targetList['target']);
            foreach ($t as $target) {
                $similar[$source]['related'][] = (int)$target;
                $similar[$source]['nrRelated']++;
            }
        }

        // For all artists with relations, sort their relations list by least relations first.
        // The idea is to have other artists that are only related to this one close by.
        foreach ($similar as &$s) {
            if ($s['nrRelated'] < 2)  {
                // trivial case
                continue;
            }
            $related = $s['related'];
            usort($related, fn ($a, $b) => $similar[$a]['nrRelated'] <=> $similar[$b]['nrRelated']);
            $s['related'] = $related;
        }
        unset($s);

        // Now sort the artists by most relations first
        uksort($similar, fn ($a, $b)
            => ($similar[$b]['nrRelated'] <=> $similar[$a]['nrRelated'] ?: $similar[$b]['score']     <=> $similar[$a]['score'])
            ?: $similar[$b]['artist_id'] <=> $similar[$a]['artist_id']
        );

        // Place the artists with the most relations first, and place
        // their relations near them, alternating on each side.
        $xOrigin = $width / 2;
        $yOrigin = $height / 2;
        $range = ($max === $min) ? $max : $max - $min;
        $placed = array_fill_keys(array_keys($similar), false);
        $seen = 0;
        foreach ($similar as &$s) {
            $id = $s['artist_id'];
            if ($placed[$id] !== false) {
                continue;
            }
            $relatedToPlace = 0;
            $relatedTotal = 0;
            foreach ($s['related'] as $r) {
                $relatedTotal++;
                if ($placed[$r] === false) {
                    $relatedToPlace++;
                }
            }
            if ($relatedToPlace > 0) {
                // Rotate the layout angles to fit this artist in, so that we can
                // pick the first and last angles off the layout list below.
                $move = (int)ceil(($relatedToPlace + 1) / 2);
                $layout = [...array_slice($layout, $move, NULL, true), ...array_slice($layout, 0, $move, true)];
            }
            if (!($relatedTotal > 0 && $seen > 1)) {
                $angle = array_shift($layout);
                $up = false;
            } else {
                // By now we have already placed two artists and we are here because the
                // current artist has a related artist to place. Have a look at the previously
                // placed artists, and if this artist is related to them, then choose first
                // or last angle in the layout list to place this artist close to them.
                $nextAngle = reset($layout);
                $prevAngle = end($layout);
                $bestNextAngle = 2 * M_PI;
                $bestPrevAngle = 2 * M_PI;
                foreach ($s['related'] as $r) {
                    if ($placed[$r] === false) {
                        continue;
                    }
                    $nextAngleDistance = fmod($nextAngle + $placed[$r], 2 * M_PI);
                    $prevAngleDistance = fmod($prevAngle + $placed[$r], 2 * M_PI);
                    if ($nextAngleDistance <= $prevAngleDistance) {
                        $bestNextAngle = min($bestNextAngle, $nextAngleDistance);
                    } else {
                        $bestPrevAngle = min($bestPrevAngle, $prevAngleDistance);
                    }
                }
                if (fmod($bestNextAngle, 2 * M_PI) < fmod($bestPrevAngle, 2 * M_PI))  {
                    $angle = array_shift($layout);
                    $up = false;
                } else {
                    $angle = array_pop($layout);
                    $up = true;
                }
            }
            $placed[$id] = $angle;
            ++$seen;

            // place this artist
            $distance = 0.9 - (($s['score'] - $min) * 0.4 / $range);
            $s['x'] = (int)(cos($angle) * $distance * $xOrigin) + $xOrigin;
            $s['y'] = (int)(sin($angle) * $distance * $yOrigin) + $yOrigin;
            $s['proportion'] = ($s['score'] / ($totalScore + 1)) ** 1.0;

            // Place their related close by, first anti-clockwise (angle
            // increasing: array_shift(), next clockwise (angle decreasing:
            // array_pop() and repeat until done.
            // There might be a way to refactor this to avoid repetition.
            foreach ($s['related'] as $r) {
                if ($placed[$r] !== false) {
                    continue;
                }
                $angle = $up ? array_shift($layout) : array_pop($layout);
                $up = !$up;
                $placed[$r] = $angle;
                ++$seen;

                // place this related artist
                $distance = 0.9 - (($similar[$r]['score'] - $min) * 0.45 / $range);
                $similar[$r]['x'] = (int)(cos($angle) * $distance * $xOrigin) + $xOrigin;
                $similar[$r]['y'] = (int)(sin($angle) * $distance * $yOrigin) + $yOrigin;
                $similar[$r]['proportion'] = ($similar[$r]['score'] / ($totalScore + 1)) ** 1.0;
            }

        }
        return $similar;
    }
}
