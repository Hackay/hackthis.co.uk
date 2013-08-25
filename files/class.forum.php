<?php
    class forum {
        private $app;

        public function __construct($app) {
            $this->app = $app;
        }

        public function isThread($slug) {
            $st = $this->app->db->prepare("SELECT thread_id AS id
                     FROM forum_threads
                     WHERE slug = :slug");
            $st->execute(array(':slug'=>$slug));
            return $st->fetch();
        }

        public function getSections($parent=null) {
            if ($parent == null) {
                $sql =  "SELECT section_id AS id, title, slug
                         FROM forum_sections
                         WHERE ISNULL(parent_id)";
                $sql .= "ORDER BY section_id ASC";
                $st = $this->app->db->prepare($sql);
                $st->execute(array(':parent'=>$parent));
                $result = $st->fetchAll();
            } else {
                $st = $this->app->db->prepare('SELECT section_id AS id, title, slug
                                    FROM forum_sections
                                    WHERE parent_id = :parent
                                    ORDER BY section_id ASC');
                $st->execute(array(':parent'=>$parent));
                $result = $st->fetchAll();
            }

            foreach($result as $res) {
                $children = $this->getSections($res->id);
                if ($children)
                    $res->children = $children;
            }

            return $result;
        }

        public function printSectionsList($cat, $menu = false, $current, $level = 1) {
            if ($menu) {
                $c = '';
                $t = 'title'.$level;
                if (isset($current->{$t}) && $current->{$t} == $cat->title)
                    $c = 'active ';
                if (isset($cat->children) && count($cat->children))
                    $c .= 'parent';

                echo "\t\t\t\t\t\t\t\t\t\t\t<li class='$c'><a href='/forum/{$cat->slug}'>{$cat->title}</a>";
                if (isset($cat->children) && count($cat->children)) {
                    echo "\n                                        <ul>\n";
                    $level++;
                    foreach($cat->children AS $child) {
                        $this->printSectionsList($child, $menu, $current, $level);
                    }
                    echo "                                        </ul>\n                                        ";
                }
                echo "</li>\n";
            } else {
                echo "<li data-value='{$cat->id}'>{$cat->title}\n";
                if (isset($cat->children) && count($cat->children)) {
                    echo "<ul>\n";
                    foreach($cat->children AS $child) {
                        $this->printSectionsList($child);
                    }
                    echo "</ul>\n";
                }
                echo "</li>\n";
            }
        }

        public function getSection($slug) { 
            $st = $this->app->db->prepare("SELECT t1.title as title1, t1.slug as slug1, t2.title as title2,
                t2.slug as slug2, t3.title as title3, t3.slug as slug3, t4.title as title4, t4.slug as slug4,
                current.section_id AS id, current.title AS title, current.slug AS slug, current.parent_id AS parent,
                child.section_id AS `child`
                FROM forum_sections AS t1
                LEFT JOIN forum_sections AS t2 ON t2.parent_id = t1.section_id
                LEFT JOIN forum_sections AS t3 ON t3.parent_id = t2.section_id
                LEFT JOIN forum_sections AS t4 ON t4.parent_id = t3.section_id
                LEFT JOIN forum_sections AS current ON current.slug = :slug
                LEFT JOIN forum_sections AS child ON current.section_id = child.parent_id
                WHERE isnull(t1.parent_id) AND (t1.slug = :slug OR t2.slug = :slug OR t3.slug = :slug OR t4.slug = :slug)");
            $st->execute(array(':slug'=>$slug));
            $result = $st->fetch();

            if ($result->slug1 == $result->slug) {
                unset($result->title2); unset($result->slug2);
                unset($result->title3); unset($result->slug3);
                unset($result->title4); unset($result->slug4);
            } else if ($result->slug2 == $result->slug) {
                unset($result->title3); unset($result->slug3);
                unset($result->title4); unset($result->slug4);
            } else if ($result->slug3 == $result->slug) {
                unset($result->title4); unset($result->slug4);
            }

            return $result;
        }

        public function getBreadcrumb($section, $thread = false, $divide='&gt;') {
            $crumb = '';
            if (isset($section->title1))
                $crumb .= '<a href="/forum/'.$section->slug1.'">'.$section->title1.'</a> ' . $divide . ' ';
            if (isset($section->title2))
                $crumb .= '<a href="/forum/'.$section->slug2.'">'.$section->title2.'</a> ' . $divide . ' ';
            if (isset($section->title3))
                $crumb .= '<a href="/forum/'.$section->slug3.'">'.$section->title3.'</a> ' . $divide . ' ';
            if (isset($section->title4))
                $crumb .= '<a href="/forum/'.$section->slug4.'">'.$section->title4.'</a> ' . $divide . ' ';

            if (!$thread)
                $crumb .= '<span class="white">Latest Threads</span>';

            return $crumb;
        }

        public function getThreadBreadcrumb($section, $thread, $divide='&gt;') {
            $crumb = '';
            for($i = 4; $i > 0; $i--):
                $title = 'title'.$i;
                $slug = 'slug'.$i;
                if (!isset($thread->{$title}))
                    continue;

                // Skip sections that match where the user currently is
                if (isset($section) && strlen($section->slug) >= strlen($thread->{$slug}))
                    continue;

                $crumb .=  "<a class='dark' href='/forum/".$thread->{$slug}."'>".$thread->{$title}."</a>";

                if ($i != 1)
                    $crumb .=  " &gt; ";            
            endfor;

            return $crumb;
        }

        public function getThreads($section, $no_replies = false, $most_popular = false) {
            $section_slug = '';
            if ($section)
                $section_slug = $section->slug;
                

            $sql = "SELECT threads.title, threads.slug, threads.closed, threads.sticky,
                users.username AS author, posts.count-1 as `count`, latest.posted AS latest, latest.username AS latest_author, posts.voices, posts.created, t1.title as title1, t1.slug as slug1, t2.title as title2,
                t2.slug as slug2, t3.title as title3, t3.slug as slug3, t4.title as title4, t4.slug as slug4,
                first.body, IF (forum_users.viewed >= latest, 1, 0) AS `viewed`, forum_users.watching
                FROM forum_threads threads
                LEFT JOIN users
                ON users.user_id = threads.owner
                LEFT JOIN (SELECT thread_id, max(posted) AS `latest`, min(posted) AS `created`, count(*) AS `count`, Count(Distinct author) AS `voices` FROM forum_posts WHERE deleted = 0 GROUP BY thread_id) posts
                ON posts.thread_id = threads.thread_id
                LEFT JOIN (SELECT thread_id, users.username, posted FROM forum_posts LEFT JOIN users ON users.user_id = author WHERE deleted = 0) latest
                ON latest.thread_id = threads.thread_id AND latest.posted = posts.latest
                LEFT JOIN (SELECT thread_id, body, posted FROM forum_posts WHERE deleted = 0) first
                ON first.thread_id = threads.thread_id AND first.posted = posts.created
                LEFT JOIN forum_users
                ON threads.thread_id = forum_users.thread_id AND forum_users.user_id = :uid

                LEFT JOIN forum_sections AS t1 ON t1.section_id = threads.section_id
                LEFT JOIN forum_sections AS t2 ON t1.parent_id = t2.section_id
                LEFT JOIN forum_sections AS t3 ON t2.parent_id = t3.section_id
                LEFT JOIN forum_sections AS t4 ON t3.parent_id = t4.section_id

                WHERE threads.slug LIKE CONCAT(:section_slug, '%') AND threads.deleted = 0 AND posts.count > 0";
            
            if ($no_replies)
                $sql .= ' AND posts.count = 1';
            
            if ($most_popular)
                $sql .= " ORDER BY `count` DESC, `voices` DESC, latest DESC";
            else
                $sql .= " ORDER BY sticky DESC, latest DESC";

            $st = $this->app->db->prepare($sql);
            $st->execute(array(':section_slug'=>$section_slug, ':uid'=>$this->app->user->uid));
            $result = $st->fetchAll();

            foreach($result AS $res) {
                $res->blurb = $this->app->parse($res->body, false);

                if ($res->closed)
                    $res->title = '[closed] ' . $res->title;
                if ($res->sticky)
                    $res->title = '[sticky] ' . $res->title;

                for($i = 4; $i > 0; $i--) {
                    $title = 'title'.$i;
                    $slug = 'slug'.$i;
                    if (!$res->{$title}) {
                        unset($res->{$title});
                        unset($res->{$slug});
                    } else {
                        break;
                    }
                }
            }

            return $result;
        }

        public function newThread($section, $title, $body) {
            $section_id = $section->id;
            $slug = $section->slug . '/' . $this->app->utils->generateSlug($title);
            try {
                $this->app->db->beginTransaction();

                $st = $this->app->db->prepare("INSERT INTO forum_threads (`section_id`, `title`, `slug`, `owner`)
                    VALUES (:section_id, :title, :slug, :uid)");
                $st->execute(array(':section_id'=>$section_id, ':title'=>$title, ':slug'=>$slug, ':uid'=>$this->app->user->uid));

                $thread_id = $this->app->db->lastInsertId();

                $st = $this->app->db->prepare("INSERT INTO forum_posts (`thread_id`, `body`, `author`)
                    VALUES (:thread_id, :body, :uid)");
                $st->execute(array(':thread_id'=>$thread_id, ':body'=>$body, ':uid'=>$this->app->user->uid));

                $this->app->db->commit();
            } catch(PDOExecption $e) {
                $this->app->db->rollback();
                return false;
            }

            return true;
        }


        public function getThread($thread_id, $page = 1, $limit = 10) {
            $st = $this->app->db->prepare("SELECT thread.thread_id AS `id`, thread.title, thread.slug, thread.deleted, section.slug AS section_slug, replies.count AS replies
                FROM forum_threads thread
                LEFT JOIN forum_sections section
                ON section.section_id = thread.section_id
                LEFT JOIN (SELECT `thread_id`, count(*)-1 AS `count` FROM forum_posts GROUP BY `thread_id`) replies
                ON replies.thread_id = thread.thread_id
                WHERE thread.thread_id = :thread_id
                LIMIT 1");
            $st->execute(array(':thread_id'=>$thread_id));
            $thread = $st->fetch();

            if (!$thread)
                return false;

            // Get question
            $st = $this->app->db->prepare("SELECT users.username, post.body, post.posted, post.updated AS edited
                FROM forum_posts post
                LEFT JOIN users
                ON users.user_id = post.author
                WHERE post.thread_id = :thread_id AND post.deleted = 0
                ORDER BY `posted` ASC
                LIMIT 1");
            $st->bindValue(':thread_id', $thread_id);
            $st->execute();
            $thread->question = $st->fetch();

            $thread->p_start = (($page-1)*$limit)+1;            

            // Get replies
            $st = $this->app->db->prepare("SELECT users.username, post.body, post.posted, post.updated AS edited
                FROM forum_posts post
                LEFT JOIN users
                ON users.user_id = post.author
                WHERE post.thread_id = :thread_id AND post.deleted = 0
                ORDER BY `posted` ASC
                LIMIT :l1, :l2");
            $st->bindValue(':thread_id', $thread_id);
            $st->bindValue(':l1', (int) $thread->p_start, PDO::PARAM_INT); 
            $st->bindValue(':l2', (int) $limit, PDO::PARAM_INT); 
            $st->execute();
            $thread->posts = $st->fetchAll();

            $thread->p_end = $thread->p_start + count($thread->posts) - 1;

            // Get section slug
            $thread->section = $this->getSection($thread->section_slug);

            //Update view status
            $st = $this->app->db->prepare("INSERT INTO forum_users (`user_id`, `thread_id`)
                    VALUES (:uid, :thread_id) ON DUPLICATE KEY UPDATE `viewed` = now()");
            $st->execute(array(':thread_id'=>$thread_id, ':uid'=>$this->app->user->uid));

            return $thread;
        }

        public function newPost($thread_id, $body) {
            if (strlen($body) <= 3)
                return false;

            $st = $this->app->db->prepare("INSERT INTO forum_posts (`thread_id`, `body`, `author`)
                VALUES (:thread_id, :body, :uid)");
            $status = $st->execute(array(':thread_id'=>$thread_id, ':body'=>$body, ':uid'=>$this->app->user->uid));

            if ($status) {
                $post_id = $this->app->db->lastInsertId();

                $notified = array($this->app->user->uid);

                // Check for mentions
                preg_match_all("/(?:(?<=\s)|^)@(\w*[0-9A-Za-z_.-]+\w*)/", $body, $mentions);
                foreach($mentions[1] as $mention) {
                    $st = $this->app->db->prepare('SELECT user_id FROM users WHERE username = :username LIMIT 1');
                    $st->execute(array(':username' => $mention));
                    $result = $st->fetch();
                    
                    if ($result) {
                        if (!in_array($result->user_id, $notified)) {
                            array_push($notified, $result->user_id);
                            $this->app->notifications->add($result->user_id, 'forum_mention', $this->app->user->uid, $post_id);
                        }
                    }
                }

                // Notify watchers
                $st = $this->app->db->prepare('SELECT forum_users.user_id AS author FROM forum_users
                                   WHERE thread_id = :thread_id AND watching = 1');
                $st->execute(array(':thread_id' => $thread_id));
                $watchers = $st->fetchAll();
                
                if ($watchers) {
                    foreach($watchers AS $watcher) {
                        if (!in_array($watcher->author, $notified)) {
                            array_push($notified, $watcher->author);
                            $this->app->notifications->add($watcher->author, 'forum_post', $this->app->user->uid, $post_id);
                        }
                    }
                }


            
                //Update view status
                $st = $this->app->db->prepare("INSERT INTO forum_users (`user_id`, `thread_id`, `watching`)
                        VALUES (:uid, :thread_id, 1) ON DUPLICATE KEY UPDATE `watching` = 1");
                $st->execute(array(':thread_id'=>$thread_id, ':uid'=>$this->app->user->uid));
            }

            return $status;
        }
    }
?>