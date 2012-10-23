<?php

$config = loadconfig('/var/www/uniquip/config.json');

switch ($_SERVER['REQUEST_METHOD'])
{
	case 'POST':
		process_post();
		break;
	case 'GET':
		process_get();
		break;
}

exit;


#a file is being posted.  Institution is specified with the
#'institution' parameter.
function process_post()
{
	$institution = $_POST['institution'];

	if (!$institution)
	{
		exit_with_status(400,"You must specify an institution");
	}

	if (!valid_institution($institution))
	{
		exit_with_status(400,"Do not recognise $institution");
	}

	if (!allow($institution, 'write'))
	{
		exit_with_status(404, "You may not post for this institution");
	}

	if (!$_FILES['file'])
	{
		exit_with_status(400,"No file posted!");
	}

	#upload file
	upload_posted_file($institution,$username);

	#if we got here, then the upload was successful.  Any problems, and exit_with_status would have been called.


	echo "Upload Successful";

}

function upload_posted_file($institution,$username)
{
	global $config;

	$upload_dir = $config['institutions'][$institution]['upload_dir'];
	$temp_file = tempnam($upload_dir,'NEW');

	move_uploaded_file($_FILES["file"]["tmp_name"], $temp_file);

	if (file_is_valid($temp_file))
	{
		swap_in_new_file($institution, $temp_file);
	}
	else
	{
		unlink($temp_file);
		#We should never get here -- if there are problems, file_is_valid calls exit_with_status
		exit_with_status(400,"Invalid CSV File");
	}
}

#future work -- detect if the files are identical, and don't swap if they are
function swap_in_new_file($institution, $temp_file)
{
	global $config;

	$upload_dir = $config['institutions'][$institution]['upload_dir'];

	$target_file = $upload_dir . 'active';

	if (file_exists($target_file))
	{
		$archive_filename = $upload_dir . 'archived-on-' . date("Y-m-d-H-i-s");
		rename($target_file, $archive_filename);
	}

	rename($temp_file, $target_file);
}


function associate_data($keys, $values)
{
	$arr = array();

	for ($i = 0; $i < count($keys); ++$i) {
		if ($values[$i])
		{
			$arr[$keys[$i]] = $values[$i];
		}
		else
		{
			$arr[$keys[$i]] = NULL;
		}
	}

	return $arr;
}


function file_is_valid($file_path)
{
	global $config;

	$row_number = 1;
	$problems = array();
	$headings_cmp = array();

	if (($handle = fopen($file_path, "r")) !== FALSE) {
		while (($row = fgetcsv($handle)) !== FALSE) {
			$row_problems = array();
			if ($row_number == 1)
			{
				foreach ($row as $heading)
				{
					array_push($headings_cmp, heading_compareval($heading));
				}

				$problems = problems_in_headings($row, $headings_cmp);

				if ($problems)
				{
					break; #don't bother checking the data if the headings are bad
				}
			}
			else
			{
				#make associative array of the data

				$row_problems = problems_in_data_row(associate_data($headings_cmp, $row), $row_number);
			}
			if ($row_problems)
			{
				$problems = array_merge($problems, $row_problems);
			}
			$row_number++;
		}
		fclose($handle);

		if ($row_number == 2) #there were no problems in the headings, but there was no data in the table
		{
			array_push($problems, "No Data in table");
		}

		if ($problems)
		{
			exit_with_status(400,"Errors in CSV File:\n\t" . implode("\n\t",$problems));
		
		}
	}
	else
	{
		exit_with_status(500, "Problem opening $file_path\n for reading");
	}

	return true;
}

function problems_in_headings($headings, $headings_cmp)
{
	global $config;

	$problems = array();

	if (count($row) != count($headings_cmp))
	{
		$problems[] = "Unexpected problems with CSV headings";
	}

	if (!$problems)
	{
		foreach ($config["csv_columns_cmp"] as $heading_cmp => $col_conf)
		{
			if (strcasecmp($col_conf['required'], 'yes') == 0)
			{
				if (!in_array($heading_cmp, $headings_cmp))
				{
					$problems[] = "Required column missing ( " . $col_conf['label'] . " )";
				}
			}
		}

		foreach ($config["requirement_groups"] as $id => $grp_headings_cmp)
		{
			$count = 0;
			foreach ($grp_headings_cmp as $heading_cmp)
			{
				if (in_array($heading_cmp, $headings_cmp))
				{
					$count++;
				}
			}
			if ($count < 1)
			{
				$msg = 'At least one of columns [';

				$labels = array();
				foreach ($grp_headings_cmp as $heading_cmp)
				{
					$labels[] = $config["csv_columns_cmp"][$heading_cmp]['label'];
				}
				$msg .= implode(',',$labels);
				$msg .= '] must be present';

				$problems[] = $msg;
			}
		}

	}


	if (!$problems)
	{
		for ($i = 0; $i < count($row); ++$i) {
			if (
				( $config["csv_columns"][$i]["validate_heading"] == 'yes' ) &&
				( $row[$i] != $config["csv_columns"][$i]["label"] )
			)
			{
				array_push($problems, "Heading " . $row[$i] . ' does not match ' . $config["csv_columns"][$i]["label"]);
			}
		}
	}




	return $problems;
}

function problems_in_data_row($row, $rowindex) 
{
	global $config;

	$problems = array();

	foreach ($row as $heading_cmp => $val)
	{
		$col_conf = $config["csv_columns_cmp"][$heading_cmp];

		if (
			($col_conf["required"] == 'yes') &&
			!$val
		){
			array_push($problems, "Row $rowindex, Column " . ($i+1) . ": Required Field Missing");
		}

		if ($val && $col["validate_data"])
		{
			$invalid = 0;
			switch ($col_conf["type"]){
#no check needed for text -- we'll accept anything
				case 'url':
					if (filter_var($val, FILTER_VALIDATE_URL) === false) {
						$invalid = 1;
					}
					break;
				case 'email':
					if (filter_var($val, FILTER_VALIDATE_EMAIL) === false) {
						$invalid = 1;
					}
					break;
				case 'yes/no':
					if (
						(strcasecmp($val, 'yes') == 0) || 
						(strcasecmp($val, 'no') == 0)
					)
					{
						$invalid = 1;
					}
					break;
				case 'wikipedia_url':
					if (filter_var($val, FILTER_VALIDATE_URL) === false) {
						$invalid = 1;
					}
					#just a quick and dirty check
					if (!strstr($val, 'wikipedia')) {
						$invalid = 1;
					}
					break;
				case 'telephone_number':
					#a pretty permissive regexp, allowing anything at the start, but at least 6 telephone-number-like characters at the end.
					$opts = array( "options" => array( "regexp" => '/^.*\s*\+?[0-9 ()-]{6,}*$/' ));
					if (filter_var($n, FILTER_VALIDATE_REGEXP, $opts ) === false) {
						$invalid = 1;
					}
					break;
			}
			if ($invalid)
			{
				array_push($problems, "Row $rowindex: " . $config["csv_columns_cmp"][$heading_cmp]['label'] . " is not of type " . $col["type"]);
			}
		}
	}

	#at least one of the values needs to be set
	foreach ($config["requirement_groups"] as $id => $grp_headings_cmp)
	{
		$count = 0;
		foreach ($grp_headings_cmp as $heading_cmp)
		{
			if ($row[$heading_cmp])
			{
				$count++;
			}
		}
		if ($count < 1)
		{
			$msg = "Row $rowindex: At least one of [";

			$labels = array();
			foreach ($grp_headings_cmp as $heading_cmp)
			{
				$labels[] = $config["csv_columns_cmp"][$heading_cmp]['label'];
			}
			$msg .= implode(',',$labels);
			$msg .= '] must be present';

			$problems[] = $msg;
		}
	}


	foreach ($config["requirement_groups"] as $groupid => $grp_headings_cmp)
	{
		$filled_flag = 0;
		foreach ($group_headings_cmp as $column_id => $value)
		{
			if ($value) { $filled_flag = 1; };
			array_push($column_ids, $column_id);
		}
		if (!$filled_flag)
		{
			array_push($problems, "Row $rowindex, Columns " . implode(',',$column_ids) . ": At least one must be filled");
		}
	}
	
	return $problems;
}



#a user is attempting to read or write to a file.  Can they?
#File names are either institution names or 'combined' for the combined file
function allow($file, $action)
{
	global $config;

	$username = $_SERVER['REMOTE_USER'];

	if (!valid_user($username))
	{
		exit_with_status(400,"Do not recognise user $username");
	}

	$permissions = $config["users"][$username]["can_$action"];

	if (in_array($institution, $permissions) || in_array('all', $permissions))
	{
		return true;
	}
	return false;
}


function process_get()
{
	global $config;

	if (isset($_GET["file"]))
	{
		$filename = $_GET["file"];
		if ($filename == 'template.csv')
		{
			send_template();
		}
		elseif ($filename == 'combined.csv')
		{
			if (allow('combined','read'))
			{
				$file_path = $config["system"]["base_path"] . $config["system"]["combined_base"] . 'active';
				send_file($file_path, 'combined.csv');
			}
			else
			{
				exit_with_status(403,"I'm afraid I can't let you do that");
			}
		}
		elseif ($filename == 'status.csv')
		{
			#generate status

		}
		else
		{
			#must be an 'institution.csv' file
			$parts = explode('.',$filename);
			if (valid_institution($parts[0] and $parts[1] == 'csv'))
			{

				send_institution_file($parts[0]);
			}
			else
			{
				exit_with_status(404,"File parameter not recognised");
			}
		}
	}
	else
	{
		#roll back to sending a simple upload form.
		output_query_form();
	}
}

#send a non 200 status with a helpful message
function exit_with_status($code, $message)
{
	header('Content-Type: text/plain; charset=utf-8');
	header(status_code_string($code),1,$code);
	echo $message;
	exit;
}

function status_code_string($code)
{
	switch ($code){
		case 202: return 'HTTP/1.0 202 Accepted';
		case 400: return 'HTTP/1.0 400 Bad Request';
		case 401: return 'HTTP/1.0 401 Unauthorized';
		case 403: return 'HTTP/1.0 403 Forbidden';
		case 404: return 'HTTP/1.0 404 Not Found';
		case 500: return 'HTTP/1.0 500 Internal Server Error';
	}
}


function send_template()
{
	global $config;

	$row = array();

	foreach ($config["csv_columns"] as $label => $info )
	{
		array_push($row, $label);
	}
	$rows = array( $row );

	send_csv($rows, 'template.csv');
}

function send_csv($rows, $filename)
{
	// output headers so that the file is downloaded rather than displayed
	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename=template.csv');

	// create a file pointer connected to the output stream
	$output = fopen('php://output', 'w');

	foreach ($rows as $row)
	{
		fputcsv($output, $row);
	}

	log_event('EVENT',"$filename sent");
	exit;
}

function send_file($file, $filename, $mimetype = "text/csv")
{
	http_send_content_disposition($filename, true);
	http_send_content_type($mimetype);
	http_throttle(0.1, 2048);
	http_send_file($file);
}

function log_event($event_type, $msg)
{
	global $config;

	$log_dir = $config["system"]["base_path"];
	$log_dir .= $config["system"]["log_base"];
	create_dir_if_needed($log_dir);
	$log_file = $log_dir . $config["system"]["logfile_name"];

	$fp = fopen($log_file, 'a');

	if (!$fp)
	{
		die("Couldn't open $log_file for writing\n");
	}


	$log_parts = array();

	array_push($log_parts, $event_type);
	array_push($log_parts, date("Y-m-d H:i:s"));
	array_push($log_parts, $_SERVER['REMOTE_HOST']);
	array_push($log_parts, $msg);

##need username too


	$log_line = implode("\t",$log_parts) . "\n";

	if (!fwrite($fp, $log_line))
	{
		die("Couldn't write to $log_file\n");
	}

	fclose($fp);
}


function output_query_form()
{
	$page = '
<html>
<head>
<title>Please upload a file</title>
</head>
<body>
<form method="post" action="index.php" enctype="multipart/form-data">
<label for="file">File:</label>
<input type="file" name="file" id="file" />
<br />
<label for="institution">Institution:</label>
<select name="institution">';

	$page .= institution_select_options();

	$page .= '
</select>
<br/>
<input type="submit" name="submit" value="Submit" />
</form>
</body>
</html>
';
	echo $page;
}

function institution_select_options()
{
	global $config;

	$options = '<option value="" selected="selected">Select an Institution...</option>';

	asort($config['institutions']);
	foreach ($config['institutions'] as $id => $info)
	{
		$options .= '<option value="' . $id . '">' . $info['name'] . '</option>';
	}

	return $options;
}

function valid_institution($institution)
{
	global $config;

	return array_key_exists($institution, $config["institutions"]);
}

function valid_user($institution)
{
	global $config;

	return array_key_exists($institution, $config["users"]);
}



function loadconfig($filename)
{

	$data = file_get_contents($filename);

	if (!$data)
	{
		$msg = "Couldn't open $filename";
		die("$msg\n");
	}

	$decoded = json_decode($data,1);

	if (!$decoded)
	{
		$msg = "Couldn't decode $filename";
		die("$msg\n");
	}

	$upload_base = $decoded["system"]["base_path"];
	$upload_base .= $decoded["system"]["upload_base"];

	#Set each institution's upload directorory
	#Create all upload directories if they're needed
	foreach ($decoded["institutions"] as $id => &$info)
	{
		$upload_dir = $upload_base . $id . '/';
		$info["upload_dir"] = $upload_dir;
		create_dir_if_needed($upload_dir);
	}


	#create associative array of CSV columns based on compare values
	#create lists of requirement groups
	$decoded["csv_columns_cmp"] = array();
	$decoded["requirement_groups"] = array();
	foreach ($decoded["csv_columns"] as $heading => $info)
	{
		$compareval = heading_compareval($heading);
		if ($compareval == $heading)
		{
			#heading is compareval -- no need to do anything
			next;
		}

		if (array_key_exists($compareval,$decoded["csv_columns_cmp"]))
		{
			exit_with_status(500,"Column Heading Collision in configuration");
		}
		$decoded["csv_columns_cmp"][$compareval] = $info;
		$decoded["csv_columns_cmp"][$compareval]["label"] = $heading;

		if (array_key_exists('requirement_group',$info))
		{
			$decoded["requirement_groups"][$info['requirement_group']][] = $compareval;

		}
	}

	return $decoded;
}

function heading_compareval($str)
{
	$str = preg_replace('/\s+/', '', $str);
	$str = strtolower($str);
	return $str;
}


function create_dir_if_needed($path)
{
	if (is_dir($path)) { return; }

	if (!mkdir($path,0770,1))
	{
		#don't log this event, just die.  Logging will cause recursion.
		$msg = "couldn't create $path\n";
		die("$msg\n");
	}
}

?>
