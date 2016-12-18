<?php
require_once('utilities.php');
require_once('dbconn.php');

$app->get('/SteamGifts/IUsers/GetUserInfo/', function($request, $response) {
	// Define the constant for the max difference of time for cached data
	define('MAX_TIME_PROFILE_CACHE', 43200);

	global $db;

	$params = $request->getQueryParams();
	$bid = false;
	if (isset($params['id'])) {
		$id = $params['id'];
		if (preg_match("/^[0-9]+$/", $id) != 1) {
			return $response->withHeader('Access-Control-Allow-Origin', '*')
			->withHeader('Content-type', 'application/json')->withJson(array(
			"errors" => array(
				"code" => 400,
				"message" => "The id contains non numeric characters")), 400, JSON_PRETTY_PRINT);
		}
		$bid = true;

	} elseif (isset($params['user'])) {
		$user = $params['user'];
		if (preg_match("/^[A-Za-z0-9]+$/", $user) != 1) {
			return $response->withHeader('Access-Control-Allow-Origin', '*')
			->withHeader('Content-type', 'application/json')->withJson(array(
			"errors" => array(
				"code" => 400,
				"message" => "The nick contains non alphanumeric characters")), 400, JSON_PRETTY_PRINT);
		}

	} else {
		return $response->withHeader('Access-Control-Allow-Origin', '*')
		->withHeader('Content-type', 'application/json')->withJson(array(
		"errors" => array(
			"code" => 400,
			"message" => "Missing or invalid required parameters")), 400, JSON_PRETTY_PRINT);
	}


	// I turn the filters' value into an array to filter the output data. I use
	//strpos to check if there are commas on the string value, and if there are
	//split it with the comma as separator to get an array of values if any
	$bfilters = false;
	$valid_filters = array(
		'steamid64' => null,
		'nickname' => null,
		'role' => null,
		'last_online' => null,
		'registered' => null,
		'comments' => null,
		'gibs_entered' => null,
		'gifts_won' => null,
		'gifts_won_value' => null,
		'gifts_sent' => null,
		'gifts_sent_value' => null,
		'gifts_awaiting_feedback' => null,
		'gifts_not_sent' => null,
		'contributor_level' => null,
		'suspension' => null
	);

	if (isset($params['filters'])) {
		if (strpos($params['filters'], ',') != false) {
			$filters = explode(",", $params["filters"]);
		} else {
			$filters = array($params["filters"]);
		}

		// Check if the user is passing valid filters
		forEach($filters as $filter) {
			if (array_key_exists($filter, $valid_filters) === false) {
				return $response->withHeader('Access-Control-Allow-Origin', '*')
				->withHeader('Content-type', 'application/json')->withJson(array(
				"errors" => array(
					"code" => 400,
					"message" => "Invalid filters")), 400, JSON_PRETTY_PRINT);
			}
		}

		$bfilters = true;
	}


	// Ask the DB for the local copy of the user requested with/without filters
	$stmt;
	if ($bfilters) {
		$filters_string = "";
		for($i = 0; $i < count($filters); $i++) {
			if ($i == 0) {
				if ($filters[$i] == "suspension") {
					$filters_string .= "suspension_type, suspension_end_time";
					continue;
				}
				$filters_string .= $filters[$i];
			} else {
				if ($filters[$i] == "suspension") {
					$filters_string .= ", suspension_type, suspension_end_time";
					continue;
				}
				$filters_string .= ", " . $filters[$i];
			}
		}

		if ($bid) {
			$stmt = $db->query("SELECT COUNT(*) AS count, " . $filters_string . ", UNIX_TIMESTAMP(last_checked) AS last_checked FROM IUsers WHERE steamid64=" . $id);
		} else {
			$stmt = $db->query("SELECT COUNT(*) AS count, " . $filters_string . ", UNIX_TIMESTAMP(last_checked) AS last_checked FROM IUsers WHERE nickname='" . $user . "'");
		}

		unset($filters_string);

	} else {
		if ($bid) {
			$stmt = $db->query("SELECT COUNT(*) AS count, steamid64, nickname, role, last_online, registered, comments, gibs_entered, gifts_won, gifts_won_value, gifts_sent, gifts_sent_value, gifts_awaiting_feedback, gifts_not_sent, contributor_level, suspension_type, suspension_end_time, UNIX_TIMESTAMP(last_checked) AS last_checked FROM IUsers WHERE steamid64=" . $id);
			echo "SELECT COUNT(*) AS count, steamid64, nickname, role, last_online, registered, comments, gibs_entered, gifts_won, gifts_won_value, gifts_sent, gifts_sent_value, gifts_awaiting_feedback, gifts_not_sent, contributor_level, suspension_type, suspension_end_time, UNIX_TIMESTAMP(last_checked) AS last_checked FROM IUsers WHERE steamid64=" . $id . "\n";

		} else {
			$stmt = $db->query("SELECT COUNT(*) AS count, steamid64, nickname, role, last_online, registered, comments, gibs_entered, gifts_won, gifts_won_value, gifts_sent, gifts_sent_value, gifts_awaiting_feedback, gifts_not_sent, contributor_level, suspension_type, suspension_end_time, UNIX_TIMESTAMP(last_checked) AS last_checked FROM IUsers WHERE nickname='" . $user . "'");
			echo "SELECT COUNT(*) AS count, steamid64, nickname, role, last_online, registered, comments, gibs_entered, gifts_won, gifts_won_value, gifts_sent, gifts_sent_value, gifts_awaiting_feedback, gifts_not_sent, contributor_level, suspension_type, suspension_end_time, UNIX_TIMESTAMP(last_checked) AS last_checked FROM IUsers WHERE nickname='" . $user . "'\n";
		}
	}

	// Check if the response from the DB has count 0, meaning there was no row
	//matching the user requested; if steamid64 is missing, meaning the row just
	//contains the nickname, probably populated by some other method; or if the
	//data is outdated. If any of these is true, meaning 0 rows or outdated data,
	//we ask SG for the profile instead
	$row = $stmt->fetch(PDO::FETCH_ASSOC);
	if ($row['count'] == 1 && isset($row['steamid64']) && (time() - $row['last_checked']) <= MAX_TIME_PROFILE_CACHE) {
		echo "If row count == 1\n";
		if ($bfilters) {
			$filtered_data = $row;
			unset($filtered_data['count']);
			unset($filtered_data['last_checked']);

			if (array_key_exists('suspension', $filters)) {
				unset($filtered_data['suspension_type']);
				unset($filtered_data['suspension_end_time']);

				$filtered_data['suspension'] = array(
					'type' => $row['suspension_type'],
					'end_time' => $row['suspension_end_time']
				);
			}

			echo "If row count == 1, bfilters true\n";
			return $response->withHeader('Access-Control-Allow-Origin', '*')
			->withHeader('Content-type', 'application/json')->withJson($filtered_data, 200, JSON_PRETTY_PRINT);

		} else {
			$data = $row;
			unset($data['suspension_type']);
			unset($data['suspension_end_time']);
			unset($data['count']);
			unset($data['last_checked']);

			$data['suspension'] = array(
				'type' => $row['suspension_type'],
				'end_time' => $row['suspension_end_time']
			);

			echo "If row count == 1, bfilters false\n";
			return $response->withHeader('Access-Control-Allow-Origin', '*')
			->withHeader('Content-type', 'application/json')->withJson($data, 200, JSON_PRETTY_PRINT);
		}
	} else {
		echo "Else, requesting page\n";
		// If count happened to be 0 or outdated data, we then request the page
		if ($bid) {
			$page_req = get_sg_page("https://www.steamgifts.com/go/user/" . $id);
		} else {
			$page_req = get_sg_page("https://www.steamgifts.com/user/" . $user);
		}
	}

	unset($stmt);

	// Parsing the html file
	$html = str_get_html($page_req);

	// Creating the barebone structure of the response JSON
	$data = array(
		'steamid64' => null,
		'nickname' => null,
		'role' => null,
		'last_online' => null,
		'registered' => null,
		'comments' => null,
		'gibs_entered' => null,
		'gifts_won' => null,
		'gifts_won_value' => null,
		'gifts_sent' => null,
		'gifts_sent_value' => null,
		'gifts_awaiting_feedback' => null,
		'gifts_not_sent' => null,
		'contributor_level' => null,
		'suspension' => array(
			'type' => null,
			'end_time' => null
		)
	);

	// In case the user wants filtered data we should return this instead
	$filtered_data = array();

	// Get SteamID64
	preg_match("/(\d+)/", $html->find(".sidebar__shortcut-inner-wrap a[href*='steamcommunity.com']")[0]->href, $steam_id);
	$data['steamid64'] = intval($steam_id[0]);

	if (isset($filters) && in_array('steamid64', $filters)) {
		$filtered_data['steamid64'] = $data['steamid64'];
	}

	unset($steam_id);

	// Get nickname
	$data['nickname'] = $html->find(".featured__heading__medium")[0]->innertext;

	if (isset($filters) && in_array('nickname', $filters)) {
		$filtered_data['nickname'] = $data['nickname'];
	}

	// Role translation dictionary
	$role_numbers = array(
		"Guest" => 0,
		"Member" => 1,
		"Bundler" => 2,
		"Developer" => 3,
		"Support" => 4,
		"Moderator" => 5,
		"Super Mod" => 6,
		"Admin" => 7
	);

	// Get info of the rows next to the avatar
	foreach($html->find(".featured__table__row") as $elem) {
		switch($elem->children(0)->innertext) {
			case 'Role':
				$data['role'] = $role_numbers[$elem->children(1)->children(0)->innertext];

				if (isset($filters) && in_array('role', $filters)) {
					$filtered_data['role'] = $data['role'];
				}
				break;
			case 'Last Online':
				if ($elem->children(1)->children(0)->class !== null && $elem->children(1)->children(0)->class == "featured__online-now") {
					$data['last_online'] = 0;
				} else {
					$data['last_online'] = intval($elem->children(1)->children(0)->getAttribute('data-timestamp'));
				}

				if (isset($filters) && in_array('last_online', $filters)) {
					$filtered_data['last_online'] = $data['last_online'];
				}
				break;
			case 'Registered':
				$data['registered'] = intval($elem->children(1)->children(0)->getAttribute('data-timestamp'));

				if (isset($filters) && in_array('registered', $filters)) {
					$filtered_data['registered'] = $data['registered'];
				}
				break;
			case 'Comments':
				$data['comments'] = intval(str_replace(",", "", $elem->children(1)->innertext));

				if (isset($filters) && in_array('comments', $filters)) {
					$filtered_data['comments'] = $data['comments'];
				}
				break;
			case 'Giveaways Entered':
				$data['gibs_entered'] = intval(str_replace(",", "", $elem->children(1)->innertext));

				if (isset($filters) && in_array('gibs_entered', $filters)) {
					$filtered_data['gibs_entered'] = $data['gibs_entered'];
				}
				break;
			case 'Gifts Won':
				$data['gifts_won'] = intval(str_replace(",", "", $elem->children(1)->children(0)->innertext));

				$index = strpos($elem->children(1)->plaintext, " ");
				$data['gifts_won_value'] = floatval(str_replace(array(",", ")"), "", substr($elem->children(1)->plaintext, $index + 3)));

				unset($index);

				if (isset($filters)) {
					if (in_array('gifts_won', $filters)) {
						$filtered_data['gifts_won'] = $data['gifts_won'];
					}

					if (in_array('gifts_won_value', $filters)) {
						$filtered_data['gifts_won_value'] = $data['gifts_won_value'];
					}
				}
				break;
			case 'Gifts Sent':
				$data['gifts_sent'] = intval(str_replace(",", "", $elem->children(1)->children(0)->children(0)->innertext));

				$index = strpos($elem->children(1)->children(0)->plaintext, " ");
				$data['gifts_sent_value'] = floatval(str_replace(array(",", ")"), "", substr($elem->children(1)->children(0)->plaintext, $index + 3)));

				unset($index);

				if (isset($filters)) {
					if (in_array('gifts_sent', $filters)) {
						$filtered_data['gifts_sent'] = $data['gifts_sent'];
					}

					if (in_array('gifts_sent_value', $filters)) {
						$filtered_data['gifts_sent_value'] = $data['gifts_sent_value'];
					}
				}

				$gifts_feedback_matches;
				preg_match("/(\d+).+(\d+)/", str_replace(",", "", $elem->children(1)->children(0)->title), $gifts_feedback_matches);
				$data['gifts_awaiting_feedback'] = intval($gifts_feedback_matches[0]);
				$data['gifts_not_sent'] = intval($gifts_feedback_matches[0]);

				unset($gifts_feedback_matches);

				if (isset($filters)) {
					if (in_array('gifts_awaiting_feedback', $filters)) {
						$filtered_data['gifts_awaiting_feedback'] = $data['gifts_awaiting_feedback'];
					}

					if (in_array('gifts_not_sent', $filters)) {
						$filtered_data['gifts_not_sent'] = $data['gifts_not_sent'];
					}
				}
				break;
			case 'Contributor Level':
				$data['contributor_level'] = floatval($elem->children(1)->children(0)->title);

				if (isset($filters) && in_array('contributor_level', $filters)) {
					$filtered_data['contributor_level'] = $data['contributor_level'];
				}
				break;
		}
	}

	// Get suspension info if any
	$suspension_info = $html->find('.sidebar__suspension');
	if (!empty($suspension_info)) {
		// Suspension translation numbers
		$suspension_numbers = array(
			"Suspended" => 0,
			"Banned" => 1
		);

		$data['suspension']['type'] = $suspension_numbers[trim($suspension_info[0]->plaintext)];

		$suspension_time = $html->find('.sidebar__suspension-time');
		if ($data['suspension']['type'] == 0 && !empty($suspension_time) && $suspension_time[0]->first_child() !== null) {
			$data['suspension']['end_time'] = intval($suspension_time[0]->children(0)->getAttribute('data-timestamp'));
		}
		unset($suspension_time);

		if (isset($filters) && in_array('suspension', $filters)) {
			$filtered_data['suspension'] = $data['suspension'];
		}
	}
	unset($suspension_info);


	// Check if count from $row was empty and use INSERT. UPDATE is it wasn't 0
	if ($row['count'] == 0) {
		echo "Inserting info\n";
		$sql_string = "INSERT INTO IUsers (steamid64, nickname, role, last_online, registered, comments, gibs_entered, gifts_won, gifts_won_value, gifts_sent, gifts_sent_value, gifts_awaiting_feedback, gifts_not_sent, contributor_level, suspension_type, suspension_end_time) VALUES (:steamid64, :nickname, :role, :last_online, :registered, :comments, :gibs_entered, :gifts_won, :gifts_won_value, :gifts_sent, :gifts_sent_value, :gifts_awaiting_feedback, :gifts_not_sent, :contributor_level, :suspension_type, :suspension_end_time)";

	} else {
		echo "Updating info\n";
		$sql_string = "UPDATE IUsers SET steamid64=:steamid64, nickname=:nickname, role=:role, last_online=:last_online, registered=:registered, comments=:comments, gibs_entered=:gibs_entered, gifts_won=:gifts_won, gifts_won_value=:gifts_won_value, gifts_sent=:gifts_sent, gifts_sent_value=:gifts_sent_value, gifts_awaiting_feedback=:gifts_awaiting_feedback, gifts_not_sent=:gifts_not_sent, contributor_level=:contributor_level, suspension_type=:suspension_type, suspension_end_time=:suspension_end_time, last_checked=null WHERE steamid64=" . $data['steamid64'];
	}

	// Prepare the statement and execute it
	$stmt = $db->prepare($sql_string);
	$stmt->execute(array(
		':steamid64' => $data['steamid64'],
		':nickname' => $data['nickname'],
		':role' => $data['role'],
		':last_online' => $data['last_online'],
		':registered' => $data['registered'],
		':comments' => $data['comments'],
		':gibs_entered' => $data['gibs_entered'],
		':gifts_won' => $data['gifts_won'],
		':gifts_won_value' => $data['gifts_won_value'],
		':gifts_sent' => $data['gifts_sent'],
		':gifts_sent_value' => $data['gifts_sent_value'],
		':gifts_awaiting_feedback' => $data['gifts_awaiting_feedback'],
		':gifts_not_sent' => $data['gifts_not_sent'],
		':contributor_level' => $data['contributor_level'],
		':suspension_type' => $data['suspension']['type'],
		':suspension_end_time' => $data['suspension']['end_time']
	));

	if ($bfilters) {
		return $response->withHeader('Access-Control-Allow-Origin', '*')
		->withHeader('Content-type', 'application/json')->withJson($filtered_data, 200, JSON_PRETTY_PRINT);
	} else {
		return $response->withHeader('Access-Control-Allow-Origin', '*')
		->withHeader('Content-type', 'application/json')->withJson($data, 200, JSON_PRETTY_PRINT);
	}
});
?>