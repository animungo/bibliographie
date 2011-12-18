<?php
define('BIBLIOGRAPHIE_OUTPUT_BODY', false);

require dirname(__FILE__).'/../init.php';

$text = 'An error occurred!';
$status = 'error';
switch($_GET['task']){
	case 'mergePersons':
		$into = bibliographie_authors_get_data($_GET['into']);
		$delete = bibliographie_authors_get_data($_GET['delete']);

		if(is_object($into) and is_object($delete) and $into->author_id != $delete->author_id){
			$publications = array_merge(
				array_diff(bibliographie_authors_get_publications($delete->author_id), bibliographie_authors_get_publications($into->author_id)),
				array_diff(bibliographie_authors_get_publications($delete->author_id, true), bibliographie_authors_get_publications($into->author_id, true))
			);

			bibliographie_purge_cache('author_');

			if(count($publications) > 0){
				$linkPublications = DB::getInstance()->prepare('UPDATE
	`'.BIBLIOGRAPHIE_PREFIX.'publicationauthorlink`
SET
	`author_id` = :into_id
WHERE
	FIND_IN_SET(`pub_id`, :publications) AND
	`author_id` = :delelete_id');
				$linkPublications->execute(array(
					'into_id' => (int) $into->author_id,
					'delelete_id' => (int) $delete->author_id,
					'publications' => array2csv($publications)
				));

				echo '<span class="success"><strong>'.$linkPublications->rowCount().' publication</strong>(s) have been relinked!</span><br />';
			}

			if(bibliographie_authors_delete($delete->author_id))
				echo '<span class="success">Second author has been deleted!</span><br /><br />';
			else
				echo '<span class="error">'.bibliographie_authors_parse_data($delete->author_id, array('linkProfile' => true)).' could not have been deleted because he still has publications, that were not transferred to '.bibliographie_authors_parse_data($into->author_id, array('linkProfile' => true)).'!</span><br /><br />';

			bibliographie_maintenance_print_author_profile($into->author_id, (int) $_GET['into_group']);
		}else
			echo '<span class="error">You did not provide two distinct authors!</span>';
	break;

	case 'positionPerson':
		bibliographie_maintenance_print_author_profile($_GET['person_id'], (int) $_GET['group_id']);
	break;

	case 'similarPersons':
		header('Content-Type: text/plain; charset=UTF-8');
		$similarPersons = array();

		$sounds = DB::getInstance()->prepare('SELECT
	`sound`
FROM (
	SELECT `sound`, COUNT(*) AS `count` FROM (
		SELECT CONCAT(SOUNDEX(`surname`), SOUNDEX(`firstname`)) AS `sound` FROM `'.BIBLIOGRAPHIE_PREFIX.'author`
	) gimmeSound
	GROUP BY
		`sound`
) gimmeCount
WHERE
	`count` > 1
ORDER BY
	`sound`');
		$sounds->execute();

		if($sounds->rowCount() > 0){
			$sounds = array_slice($sounds->fetchAll(PDO::FETCH_COLUMN, 0), 0, 50);

			$groups = DB::getInstance()->prepare('SELECT
		`author_id`
	FROM
		`'.BIBLIOGRAPHIE_PREFIX.'author`
	WHERE
		CONCAT(SOUNDEX(`surname`), SOUNDEX(`firstname`)) = :sound
	ORDER BY
		`author_id`');
			$groups->setFetchMode();

			$unsimilarGroups = bibliographie_maintenance_get_unsimilar_groups();

			foreach($sounds as $sound){
				$groups->execute(array(
					'sound' => $sound
				));

				if($groups->rowCount() > 0){
					$group = $groups->fetchAll(PDO::FETCH_COLUMN, 0);

					if(!in_array($group, $unsimilarGroups)){
						foreach($group as $ix => $id)
							$group[$ix] = array(
								'id' => $id,
								'name' => bibliographie_authors_parse_data($id, array('linkProfile' => true))
							);

						$similarPersons[] = $group;
					}
				}
			}
		}

		echo json_encode($similarPersons);
	break;

	case 'consistencyChecks':
		switch($_GET['consistencyCheckID']){
			case 'authors_charsetArtifacts':
				$authors = DB::getInstance()->prepare('SELECT `author_id` FROM `'.BIBLIOGRAPHIE_PREFIX.'author` WHERE CONCAT(`firstname`, `von`, `surname`, `jr`) NOT REGEXP "^([abcdefghijklmnopqrstuvwxyzäöüßáéíóúàèìòùç[.full-stop.][.\'.][.hyphen.][.space.]]*)\$" ORDER BY `surname`, `firstname`');
				$authors->setFetchMode(PDO::FETCH_OBJ);
				$authors->execute();

				if($authors->rowCount() > 0){
					echo '<p class="error">Found '.$authors->rowCount().' authors with charset artifacts!</p>';
					$authorIDs = $authors->fetchAll(PDO::FETCH_COLUMN, 0);

					echo '<table class="dataContainer">';
					echo '<tr><th> </th><th>Name</th></tr>';

					foreach($authorIDs as $author_id){
						$author = bibliographie_authors_get_data($author_id);

						echo '<tr>';
						echo '<td><a href="'.BIBLIOGRAPHIE_WEB_ROOT.'/authors/?task=authorEditor&amp;author_id='.((int) $author->author_id).'">'.bibliographie_icon_get('user-edit').'</a></td>';
						echo '<td><a href="'.BIBLIOGRAPHIE_WEB_ROOT.'/authors/?task=showAuthor&amp;author_id='.((int) $author->author_id).'">'.bibliographie_authors_parse_data($author->author_id, array('linkProfile' => true)).'</a></td>';
						echo '</tr>';
					}

					echo '</table>';
				}else
					echo '<p class="success">No authors with charset artifacts.</p>';
			break;

			case 'authors_withoutPublications':
				$authorIDs = array();
				$relationIDs = array();

				$authors = DB::getInstance()->prepare('SELECT `author_id` FROM `'.BIBLIOGRAPHIE_PREFIX.'author`');
				$authors->setFetchMode(PDO::FETCH_OBJ);
				$authors->execute();

				if($authors->rowCount() > 0)
					$authorIDs = $authors->fetchAll(PDO::FETCH_COLUMN, 0);

				$relations = DB::getInstance()->prepare('SELECT `author_id` FROM `'.BIBLIOGRAPHIE_PREFIX.'publicationauthorlink` GROUP BY `author_id`');
				$relations->setFetchMode(PDO::FETCH_OBJ);
				$relations->execute();

				if($relations->rowCount() > 0)
					$relationIDs = $relations->fetchAll(PDO::FETCH_COLUMN, 0);

				$authorsWithoutPublications = array_values(array_diff($authorIDs, $relationIDs));

				if(count($authorsWithoutPublications) > 0){
					echo '<p class="error">Found <strong>'.count($authorsWithoutPublications).' authors without publications.</strong>';
					echo '<table class="dataContainer">';
					echo '<tr>';
					echo '<th style="width: 5%"></th>';
					echo '<th style="width: 95%">Name</th>';
					echo '</tr>';
					foreach($authorsWithoutPublications as $author_id){
						echo '<tr>';
						echo '<td>'.bibliographie_icon_get('user-delete').'</td>';
						echo '<td>'.bibliographie_authors_parse_data($author_id, array('linkProfile' => true)).'</td>';
						echo '</tr>';
					}
					echo '</table>';
				}else
					echo '<p class="success">No authors without publications were found.</p>';
			break;

			case 'publications_withoutTopic':
				$publicationsArray = array();
				$publicationLinksArray = array();

				$publications = DB::getInstance()->prepare('SELECT `pub_id` FROM `'.BIBLIOGRAPHIE_PREFIX.'publication` GROUP BY `pub_id`');
				$publications->setFetchMode(PDO::FETCH_OBJ);
				$publications->execute();
				if($publications->rowCount() > 0)
					$publicationsArray = $publications->fetchAll(PDO::FETCH_COLUMN, 0);

				$publicationLinks = DB::getInstance()->prepare('SELECT `pub_id` FROM `'.BIBLIOGRAPHIE_PREFIX.'topicpublicationlink` GROUP BY `pub_id`');
				$publicationLinks->setFetchMode(PDO::FETCH_OBJ);
				$publicationLinks->execute();
				if($publicationLinks->rowCount() > 0)
					$publicationLinksArray = $publicationLinks->fetchAll(PDO::FETCH_COLUMN, 0);

				$publicationsList = array_values(array_diff($publicationsArray, $publicationLinksArray));
				echo bibliographie_publications_print_list($publicationsList);
			break;

			case 'publications_withoutTag':
				$publicationsArray = array();

				$publications = DB::getInstance()->prepare('SELECT `pub_id` FROM `'.BIBLIOGRAPHIE_PREFIX.'publication` WHERE `pub_id` NOT IN (SELECT `pub_id` FROM `'.BIBLIOGRAPHIE_PREFIX.'publicationtaglink`)');
				$publications->setFetchMode(PDO::FETCH_OBJ);
				$publications->execute();

				if($publications->rowCount() > 0){
					$publicationsArray = $publications->fetchAll(PDO::FETCH_COLUMN, 0);
					echo bibliographie_publications_print_list($publicationsArray);
				}else
					echo '<p class="success">No publications without a tag assignment were found.';
			break;

			case 'topics_loosenedSubgraphs':
				$topicsArray = array();
				$topicLinksArray = array();

				$topics = DB::getInstance()->prepare('SELECT `topic_id` FROM `'.BIBLIOGRAPHIE_PREFIX.'topics` WHERE `topic_id` != 1');
				$topics->execute();
				if($topics->rowCount() > 0)
					$topicsArray = $topics->fetchAll(PDO::FETCH_COLUMN, 0);

				$topicLinks = DB::getInstance()->prepare('SELECT `source_topic_id` FROM `'.BIBLIOGRAPHIE_PREFIX.'topictopiclink`');
				$topicLinks->execute();
				if($topicLinks->rowCount() > 0)
					$topicLinksArray = $topicLinks->fetchAll(PDO::FETCH_COLUMN, 0);

				$topics = array_diff($topicsArray, $topicLinksArray);

				if(count($topics) > 0){
					echo '<p class="error">Found '.count($topics).' topics without parent topic!</p>';
					echo '<table class="dataContainer"><tr><th style="width: 5%"> </th><th>Name</th></tr>';

					foreach($topics as $topic)
						echo '<tr><td><a href="'.BIBLIOGRAPHIE_WEB_ROOT.'/topics/?task=topicEditor&amp;topic_id='.((int) $topic).'">'.bibliographie_icon_get('folder-edit').'</td><td><a href="'.BIBLIOGRAPHIE_WEB_ROOT.'/topics/?task=showTopic&amp;topic_id='.((int) $topic).'">'.bibliographie_topics_parse_name($topic, array('linkProfile' => true)).'</td></tr>';
					echo '</table>';
				}else
					echo '<p class="success">Did not find loosened graphs!</p>';
			break;

			case 'topics_doubledNames':
				$doubledTopicNames = DB::getInstance()->prepare('SELECT * FROM (
	SELECT *, COUNT(*) AS `count` FROM `'.BIBLIOGRAPHIE_PREFIX.'topics` GROUP BY `name`
) counts
WHERE
	`count` > 1
ORDER BY
	`name`');
				$doubledTopicNames->execute();

				if($doubledTopicNames->rowCount() > 0){
					$doubledTopicNames->setFetchMode(PDO::FETCH_OBJ);
					$topics = $doubledTopicNames->fetchAll();

					echo '<p class="error">Found '.$doubledTopicNames->rowCount().' topics with doubled names.</p>';
					echo '<table class="dataContainer"><tr><th>Topic name</th> <th>Count</th></tr>';
					foreach($topics as $topic)
						echo '<tr><td>'.$topic->name.'</td><td>'.$topic->count.'</td></tr>';
					echo '</table>';
				}else
					echo '<p class="success">Found no doubled topic names.</p>';
			break;
		}
	break;
	case 'unlockTopic':
		$result = bibliographie_maintenance_unlock_topic($_GET['topic_id']);
		$text = 'The topic could not be unlocked!';
		if($result){
			$text = 'The topic has been unlocked!';
			$status = 'success';
		}

		echo json_encode(array(
			'status' => $status,
			'text' => $text
		));
	break;
}

require BIBLIOGRAPHIE_ROOT_PATH.'/close.php';