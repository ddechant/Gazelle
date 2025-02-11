<?php

/**********|| Page to show individual threads || ********************************\

Things to expect in $_GET:
    ThreadID: ID of the forum curently being browsed
    page:    The page the user's on.
    page = 1 is the same as no page

********************************************************************************/

//---------- Things to sort out before it can start printing/generating content

// Check for lame SQL injection attempts
if (!isset($_GET['threadid']) && isset($_GET['topicid'])) {
    $_GET['threadid'] = $_GET['topicid'];
}
$forumMan = new Gazelle\Manager\Forum;
if ($_GET['postid']) {
    $postId = (int)$_GET['postid'];
    $forum = $forumMan->findByPostId($postId);
    if (is_null($forum)) {
        print json_encode(['status' => 'failure']);
    }
    $threadId = $forum->findThreadIdByPostId($postId);
} elseif (isset($_GET['threadid'])) {
    $postId = false;
    $threadId = (int)$_GET['threadid'];
    $forum = $forumMan->findByThreadId($threadId);
    if (is_null($forum)) {
        print json_encode(['status' => 'failure']);
    }
}

// Make sure they're allowed to look at the page
if (!$Viewer->readAccess($forum)) {
    print json_encode(['status' => 'failure']);
    exit;
}

$threadInfo = $forum->threadInfo($threadId);
if (empty($threadInfo)) {
    json_die('failure', 'no such thread exists');
}
$forumId = $forum->id();
$perPage = $_GET['pp'] ?? $Viewer->postsPerPage();

//Post links utilize the catalogue & key params to prevent issues with custom posts per page
if ($threadInfo['Posts'] <= $perPage) {
    $PostNum = 1;
} else {
    if ((int)$_GET['post']) {
        $PostNum = (int)$_GET['post'];
    } elseif ($postId) {
        $PostNum = $forum->priorPostTotal($postId);
    } else {
        $PostNum = 1;
    }
}

$paginator = new Gazelle\Util\Paginator($perPage, (int)($_GET['page'] ?? ceil($PostNum / $perPage)));
$paginator->setTotal($threadInfo['Posts']);

// Cache catalogue from which the page is selected, allows block caches and future ability to specify posts per page
$CatalogueID = floor(($perPage * ($paginator->page() - 1)) / THREAD_CATALOGUE);
if (!$Catalogue = $Cache->get_value("thread_$threadId"."_catalogue_$CatalogueID")) {
    $DB->prepared_query("
        SELECT
            p.ID,
            p.AuthorID,
            p.AddedTime,
            p.Body,
            p.EditedUserID,
            p.EditedTime
        FROM forums_posts AS p
        WHERE p.TopicID = ?
            AND p.ID != ?
        LIMIT ? OFFSET ?
        ", $threadId, $threadInfo['StickyPostID'], THREAD_CATALOGUE, $CatalogueID * $CatalogueSize
    );
    $Catalogue = $DB->to_array(false, MYSQLI_ASSOC);
    if (!$threadInfo['isLocked'] || $threadInfo['isSticky']) {
        $Cache->cache_value("thread_$threadId"."_catalogue_$CatalogueID", $Catalogue, 0);
    }
}
$Thread = array_slice($Catalogue, ($perPage * ($paginator->page() - 1)) % THREAD_CATALOGUE, $perPage, true);

if ($_GET['updatelastread'] !== '0') {
    $LastPost = end($Thread);
    $LastPost = $LastPost['ID'];
    reset($Thread);
    if ($threadInfo['Posts'] <= $perPage * $paginator->page() && $threadInfo['StickyPostID'] > $LastPost) {
        $LastPost = $threadInfo['StickyPostID'];
    }
    //Handle last read
    if (!$threadInfo['isLocked'] || $threadInfo['isSticky']) {
        $LastRead = $DB->scalar("
            SELECT PostID
            FROM forums_last_read_topics
            WHERE UserID = ?
                AND TopicID = ?
            ", $Viewer->id(), $threadId
        );
        if ($LastRead < $LastPost) {
            $DB->prepared_query("
                INSERT INTO forums_last_read_topics
                       (UserID, TopicID, PostID)
                VALUES (?,      ?,       ?)
                ON DUPLICATE KEY UPDATE PostID = ?
                ", $Viewer->id(), $threadId, $LastPost, $LastPost
            );
        }
    }
}

$JsonPoll = null;
if ($threadInfo['NoPoll'] == 0) {
    [$Question, $Answers, $Votes, $Featured, $Closed] = $forum->pollData($threadId);
    if (!empty($Votes)) {
        $TotalVotes = array_sum($Votes);
        $MaxVotes = max($Votes);
    } else {
        $TotalVotes = 0;
        $MaxVotes = 0;
    }

    //Polls lose the you voted arrow thingy
    $UserResponse = $forum->pollVote($Viewer->id(), $threadId);
    if ($UserResponse > 0) {
        $Answers[$UserResponse] = '&raquo; ' . $Answers[$UserResponse];
    } else {
        if (!empty($UserResponse) && $forum->hasRevealVotes()) {
            $Answers[$UserResponse] = '&raquo; ' . $Answers[$UserResponse];
        }
    }

    $JsonPoll = [
        'answers'    => [],
        'closed'     => $Closed == 1,
        'featured'   => (bool)$Featured,
        'question'   => $Question,
        'maxVotes'   => $MaxVotes,
        'totalVotes' => $TotalVotes,
        'voted'      => $UserResponse !== null || $Closed || $threadInfo['isLocked'],
        'vote'       => $UserResponse ? $UserResponse - 1 : null,
    ];

    foreach ($Answers as $i => $Answer) {
        if (!empty($Votes[$i]) && $TotalVotes > 0) {
            $Ratio = $Votes[$i] / $MaxVotes;
            $Percent = $Votes[$i] / $TotalVotes;
        } else {
            $Ratio = 0;
            $Percent = 0;
        }
        $JsonPoll['answers'][] = [
            'answer'  => $Answer,
            'ratio'   => $Ratio,
            'percent' => $Percent,
        ];
    }
}

// Squeeze in stickypost
if ($threadInfo['StickyPostID']) {
    if ($threadInfo['StickyPostID'] != $Thread[0]['ID']) {
        array_unshift($Thread, $threadInfo['StickyPost']);
    }
    if ($threadInfo['StickyPostID'] != $Thread[count($Thread) - 1]['ID']) {
        $Thread[] = $threadInfo['StickyPost'];
    }
}

$userCache = [];
$userMan = new Gazelle\Manager\User;
$JsonPosts = [];
foreach ($Thread as $Key => $Post) {
    [$PostID, $AuthorID, $AddedTime, $Body, $EditedUserID, $EditedTime] = array_values($Post);
    if (!isset($userCache[$AuthorID])) {
        $userCache[$AuthorID] = $userMan->findById((int)$AuthorID);
    }
    $author = $userCache[$AuthorID];
    if (!isset($userCache[$EditedUserID])) {
        $userCache[$EditedUserID] = $userMan->findById((int)$EditedUserID);
    }
    $editor = $userCache[$EditedUserID];

    $JsonPosts[] = [
        'postId'         => $PostID,
        'addedTime'      => $AddedTime,
        'bbBody'         => $Body,
        'body'           => Text::full_format($Body),
        'editedUserId'   => $EditedUserID,
        'editedTime'     => $EditedTime,
        'editedUsername' => $editor ? $editor->username() : null,
        'author' => [
            'authorId'   => $AuthorID,
            'authorName' => $author->username(),
            'paranoia'   => $author->paranoia(),
            'donor'      => $author->isDonor(),
            'warned'     => $author->isWarned(),
            'avatar'     => $author->avatar(),
            'enabled'    => $author->isEnabled(),
            'userTitle'  => $author->title(),
        ],
    ];
}

$subscribed = (new Gazelle\Subscription($Viewer))->isSubscribed($threadId);
if ($subscribed) {
    $Cache->delete_value('subscriptions_user_new_' . $Viewer->id());
}

print json_encode([
    'status' => 'success',
    'response' => [
        'forumId'     => $forumId,
        'forumName'   => $forum->name(),
        'threadId'    => $threadId,
        'threadTitle' => $threadInfo['Title'],
        'subscribed'  => $subscribed,
        'locked'      => $threadInfo['isLocked'] == 1,
        'sticky'      => $threadInfo['isSticky'] == 1,
        'currentPage' => $paginator->page(),
        'pages'       => $paginator->pages(),
        'poll'        => empty($JsonPoll) ? null : $JsonPoll,
        'posts'       => $JsonPosts,
    ]
]);
