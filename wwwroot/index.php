<?php

##################################################################
#
#	Uniquip Facilities Sharing CSV file Aggregator
#
##################################################################
#
#	Author: Adam Field
#	Version Dates:
#		v0.1 - 2012-10-26
#
#	Purpose
#		To allow the upload of data on sharable facilities by S5 institutions.
#		To allow the download of combined data on sharable facilities by S5 institutions.
#
#	This PHP script handles all web traffic.  It is intended to run on webspace secured by
#	http basic authentication, with accounts set up in a .htpassword as specified in the
#	configuration.
#
##################################################################


#load the configuration.  Verify that this path is correct.
$config = loadconfig('/var/www/facilitiesdatadev/htdocs/uniquip/config.json');

date_default_timezone_set('Europe/London');

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

####################################################
#
#	Top-Level Function
#
####################################################


/**
* Handles the posting of an institution's file
*
* The institution parameter must be set, and the user must have
* permission to post the file.
*
*/
function process_post()
{
	global $config;

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
	get_uploaded_file($institution);

	#if we got here, then the upload was successful.  Any problems, and exit_with_status would have been called.

	#now generate the new combined file
	generate_combined_file();

	exit_with_status(200,"File Upload Successful.");
}


/**
*
* Handles get requests, allowing the download of:
*	template
*	status file
*	aggregate table
*	any uploaded table
* Note that the user must have permission to do this.
*
*/
function process_get()
{
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
				if (file_exists(aggregate_file()))
				{
					send_file(aggregate_file(), 'combined.csv');
				}
				else
				{
					exit_with_status(404,"Combined File not found");
				}
			}
			else
			{
				exit_with_status(403,"I'm afraid I can't let you do that");
			}
		}
		elseif ($filename == 'status.csv')
		{
			send_status();
		}
		else
		{
			#must be an 'institution.csv' file -- extract the id of the institution
			$parts = explode('.',$filename);
			if (valid_institution($parts[0]) and $parts[1] == 'csv')
			{
				$file = institution_active_file($parts[0]);
				if (file_exists($file))
				{
					send_file($file,$filename);
				}
				else
				{
					exit_with_status(404,"File not found -- perhaps data hasn't been uploaded");
				}	
			}
			else
			{
				exit_with_status(404,"File parameter not recognised");
			}
		}
	}
	else
	{
		#no file has been requested.  Show the front page.
		send_front_page();
	}
}

#########################################################
#
#	Validation Functions
#
#########################################################

/**
*
* Given the path of a newly uploaded file, is it a valid submission?
*
* Returns true if file is good.
* Calls exit_with_status with an appropriate message if the file is bad.
*
*/
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
					$headings_cmp[] =  heading_to_cmp($heading);
				}

				$problems = problems_in_headings($row, $headings_cmp);

				#Check for duplicate headings
				$uniq_headings_cmp = array_unique($headings_cmp);
				if (count(array_unique($headings_cmp)) != count($headings_cmp))
				{
					$problems[] = 'Duplicate heading in CSV';
				}

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
			unlink($file_path);
			exit_with_status(400,"Errors in CSV File:\n\t" . implode("\n\t",$problems));
		}
	}
	else
	{
		unlink($file_path);
		exit_with_status(500, "Problem opening $file_path\n for reading");
	}

	return true;
}

/**
*
* Is the given value of type $type?
*
*/
function valid_value($val, $type)
{
	switch ($type){
		case 'text': return true;
		case 'url':
			if (filter_var($val, FILTER_VALIDATE_URL) === false) {
				return false;
			}
			return true;
		case 'email':
			if (filter_var($val, FILTER_VALIDATE_EMAIL) === false) {
				return false;
			}
			return true;
		case 'yes/no':
			if (
				(strcasecmp($val, 'yes') == 0) || 
				(strcasecmp($val, 'no') == 0)
			)
			{
				return false;
			}
			return true;
		case 'wikipedia_url':
			if (filter_var($val, FILTER_VALIDATE_URL) === false) {
				return false;
			}
			#just a quick and dirty check
			if (!strstr($val, 'wikipedia')) {
				return false;
			}
			return true;
		case 'telephone_number':
			#a pretty permissive regexp, expecting a sequence of at least 8 telephone-number-esque characters 
			$opts = array( "options" => array( "regexp" => '/[0-9\s()-]{8,}$/' ));
			if (filter_var($val, FILTER_VALIDATE_REGEXP, $opts ) === false) {
				return false;
			}
			return true;
	}
	#unrecognised type -- return false
	return false;
}

/**
*
* Given an associative array representing a row in the table ( heading -> value),
* Return an array of issues
*
*/
function problems_in_data_row($row, $rowindex) 
{
	global $config;

	$problems = array();

	foreach ($row as $heading_cmp => $val)
	{
		$col_conf = $config["csv_input_columns_cmp"][$heading_cmp];

		if (
			($col_conf["required"] == 'yes') &&
			!$val
		){
			array_push($problems, "Row $rowindex: Required Value Missing (" . cmp_to_heading($heading_cmp) . ")");
		}

		if (
			($val !== null) &&
			$col_conf["validate_data"] && 
			(!valid_value($val, $col_conf['type']))
		)
		{
			array_push($problems, "Row $rowindex: " . cmp_to_heading($heading_cmp) . " is not of type " . $col_conf["type"]);
		}
	}

	#at least one of the values needs to be set
	foreach ($config["requirement_groups"] as $id => $grp_headings_cmp)
	{
		$count = 0;
		foreach ($grp_headings_cmp as $heading_cmp)
		{
			if ($row[$heading_cmp] != NULL)
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
				$labels[] = $config["csv_input_columns_cmp"][$heading_cmp]['label'];
			}
			$msg .= implode(',',$labels);
			$msg .= '] must be present';

			$problems[] = $msg;
		}
	}

	
	return $problems;
}

/**
*
* Given an array of headings and an identically ordered array of heading comparevalues,
* Return an array of issues (e.g. required fields missing)
*
*/
function problems_in_headings($headings, $headings_cmp)
{
	global $config;

	$problems = array();

	if (count($headings) != count($headings_cmp))
	{
		$problems[] = "Unexpected problems with CSV headings";
	}

	if (!$problems)
	{
		#required fields
		foreach ($config["csv_input_columns_cmp"] as $heading_cmp => $col_conf)
		{
			if (strcasecmp($col_conf['required'], 'yes') == 0)
			{
				if (!in_array($heading_cmp, $headings_cmp))
				{
					$problems[] = "Required column missing ( " . $col_conf['label'] . " )";
				}
			}
		}

		#groups of fields where at least one is required
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
					$labels[] = $config["csv_input_columns_cmp"][$heading_cmp]['label'];
				}
				$msg .= implode(',',$labels);
				$msg .= '] must be present';

				$problems[] = $msg;
			}
		}

	}

	return $problems;
}


#########################################################
#
#	Content Functions
#
#########################################################

/**
*
* Generate the combined file from all active submissions
*
*/
function generate_combined_file()
{
	global $config;

	$csv_rows = array();

	$source_cols = array();

	#load all sources into memory.
	foreach ($config["institutions"] as $inst => $c)
	{
		if (institution_is_active($inst))
		{
			$source_cols[$inst] = csv_to_associative_array(institution_active_file($inst));
		}
	}

	$csv_rows[] = generate_output_headings();

	#generate CSV for each institution in turn
	foreach ($source_cols as $inst => $cols)
	{
		generate_institution_rows($inst, $cols, $csv_rows);
	}

	$filepath = aggregate_file();

	write_csv($csv_rows, $filepath);
}

/**
*
* Generate CSV headings for the combined CSV file
*
*/
function generate_output_headings()
{
	global $config;

	$row = array();

	foreach ($config['csv_output_columns'] as $heading => $cconf)
	{
		$row[] = $heading;
	}
	return $row;
}

/**
*
* Generate all CSV rows for a single institution for use in the combined CSV file
*
*/
function generate_institution_rows($inst, $input_columns, &$rows)
{
	global $config;

	$row_count = 0;

	#count depth of columns (also sanity check that they're the same depth)
	foreach ($input_columns as $heading_cmp => $vals)
	{
		if ($row_count == 0) {
			$row_count = count($vals);
		}
		else
		{
			if (count($vals) != $row_count)
			{
				exit_with_status(500,"Problems aggregating CSV -- row count mismatch( " . $count($vals) . " vs $row_count)");
			}
		}
	}

	for ($i = 0; $i < $row_count; ++$i)
	{
		$row = array();		
		foreach ($config["csv_output_columns"] as $heading => $cconf)
		{
			$row[] = generate_output_value($inst, $heading, $cconf, $input_columns, $i);
		}
		$rows[] = $row;
	}

}

/**
*
* Generate that actual data to go in a single cell in the combined CSV file
*
* Heavily controled by the configuration
*
* Args:
*	$inst -- The id of the institution
*	$heading -- The heading of this column
*	$ccong -- This column's configuration
*	$input_columns -- The uploaded data we're building the combined data from
*	$i -- the row we're currently working on
*
*/
function generate_output_value($inst, $heading, $cconf, $input_columns, $i)
{
	global $config;

	switch ($cconf['type']){
		case 'config':
			return configpath_to_value($cconf['configpath'], $inst);
		case 'upload_datestamp':
			if (!$config['output_cache'][$inst]['upload_datestamp'])
			{
				$config['output_cache'][$inst]['upload_datestamp'] = institution_datestamp($inst);
			}
			return $config['output_cache'][$inst]['upload_datestamp'];
		case 'input_field':
			$h;
			if ($cconf['input_field'])
			{
				$h = heading_to_cmp($cconf['input_field']);
			}
			else
			{
				$h = heading_to_cmp($heading);
			}
			if ($input_columns[$h] && $input_columns[$h][$i])
			{
				return $input_columns[$h][$i];
			}
			else
			{
				return null;
			}
		case 'input_field_transform':
			if ($cconf['transform_type'] == 'wikipedia_to_long_lat')
			{
				$val = $input_columns[heading_to_cmp($cconf['input_field'])][$i];
				if (valid_value($val,'wikipedia_url'))
				{
					$cache = read_cache_file('wikipedia_to_lat_long');
					if (array_key_exists($val,$cache))
					{
						return $cache[$val];
					}
					else
					{
						$coords = wikipedia_to_lat_long($val);
						if ($coords === null)
						{
echo 'foo';
							return null;
						}
						$cache[$val] = $coords;
						write_cache_file('wikipedia_to_lat_long',$cache);
						return $coords;
					}

				}
				else
				{
					return null;
				}
			}
	}
	return 'FIELD OUTPUT NOT HANDLED';
}

/**
*
* Generate and send the status CSV
*
*/
function send_status()
{
	global $config;

	$csv = array();
	$csv[] = array('Institution', 'Status', 'Date Last Updated');

	foreach ($config['institutions'] as $institution => $conf)
	{
		$row = array($conf['name']);
		if (institution_is_active($institution))
		{
			$row[] = 'Active';
			$row[] = institution_datestamp($institution);
		}
		else
		{
			$row[] = 'Inactive';
			$row[] = '';
		}
		$csv[] = $row;
	}
	send_csv($csv,'status.csv');
}
/**
*
* Output the front page
*
*/
function send_front_page()
{
	global $config;

	$page = '
<html>
<head>
<title>Please upload a file</title>
</head>
<body>
<h2>Upload a File</h2>
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
<h2>Download a File</h2>
<ul>
<li><a href="/index.php?file=template.csv">Blank Template</a></li>
';

if (file_exists(aggregate_file()))
{
	$page .= '<li><a href="/index.php?file=combined.csv">Combined Data</a></li>';
}

$page .= '<li><a href="/index.php?file=status.csv">Status Overview</a></li>
<li>Institution Source Files<ul>
';

	foreach ($config['institutions'] as $id => $info)
	{
		if (institution_is_active($id))
		{
			$iname = $info["name"];
			$page .= "<li><a href='/index.php?file=$id.csv'>$iname</a></li>\n";


		}
	}


$page .= '
</ul></li></ul>
</body>
</html>
';
	echo $page;
	exit;
}

/**
*
* Generate the template from the configuration and send it
*
*/
function send_template()
{
	global $config;

	$row = array();

	foreach ($config["csv_input_columns"] as $label => $info )
	{
		array_push($row, $label);
	}
	$rows = array( $row );

	send_csv($rows, 'template.csv');
}



##########################################################
#
#	Utility Functions
#
##########################################################

/**
*
* Given an institution, reiturns true if it has an active uploaded file
* false otherwise
*
* i.e. Has this institution uploaded a file yet?
*
*/
function institution_is_active($institution)
{
	if (file_exists(institution_active_file($institution)))
	{
		return true;
	}
	return false;
}

/**
*
* Given an institution, returns the date on which its active file was uploaded
*
*/
function institution_datestamp($institution)
{
	$target_file = institution_active_file($institution);

	if (file_exists($target_file))
	{
		return date("Y-m-d",filemtime($target_file));
	}
	return NULL;
}

/**
*
* Given the path to a csv file, the function will load it into an
* associative array such that:
*	keys -- the comparevals of the column headings
*	values -- arrays with all data values in the column
*
*/
function csv_to_associative_array($filepath)
{
	$first = true;
	$headings_cmp = array();
	$columns = array();
	if (($handle = fopen($filepath, "r")) !== FALSE) {
		while (($row = fgetcsv($handle)) !== FALSE) {
			if ($first)
			{
				foreach ($row as $heading)
				{
					$heading_cmp = heading_to_cmp($heading);
					$headings_cmp[] = $heading_cmp;
					$columns[$heading_cmp] = array();
				}
				$first = false;
			}
			else
			{
				#iterate over the headings so as to insert null values if the data row is short
				for ($i = 0; $i < count($headings_cmp); ++$i) {
					if ($row[$i] != null)
					{
						$columns[$headings_cmp[$i]][] = $row[$i];
					}
					else
					{
						$columns[$headings_cmp[$i]][] = null;
					}
				}
			}			
		}
	}
	else
	{
		exit_with_status(500,"Couldn't open $filepath for reading");
	}

	return $columns;
}

/**
*
* Process and store a file that has been uploaded
*
*/
function get_uploaded_file($institution)
{
	global $config;

	$upload_dir = $config['institutions'][$institution]['upload_dir'];
	$temp_file = tempnam($upload_dir,'NEW');

	if (!move_uploaded_file($_FILES["file"]["tmp_name"], $temp_file))
	{
		exit_with_status(500,"Couldn't move uploaded file to $temp_file");
	}

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

/**
*
* Given a wikipedia URL, download data from dbpedia and extract the lat and long
*
*/
function wikipedia_to_lat_long($url)
{
	$dbpedia_url = preg_replace('/^.*wiki\//','http://dbpedia.org/data/', $url);
	$dbpedia_url .= '.ntriples';

	$triples = file_get_contents($dbpedia_url);

	$results = array();

	$long_match = '/<http:\/\/www.w3.org\/2003\/01\/geo\/wgs84_pos#long>\s*"([0-9\.-]*)"/';
	$lat_match = '/<http:\/\/www.w3.org\/2003\/01\/geo\/wgs84_pos#lat>\s*"([0-9\.-]*)"/';

	$lat = null;
	$long = null;

	if (preg_match($long_match, $triples, $results))
	{
		$long = $results[1];
	}

	if (preg_match($lat_match, $triples, $results))
	{
		$lat = $results[1];
	}

	if ( ($lat === null) || ($long === null) )
	{
		return null;
	}
	return "$lat,$long";

}

/**
*
* Converts a config path (as an array) into the value in the configuration
*
* e.g. ['institutions','$ID','name'] returns 'Southampton' for the institution with ID 'southampton'
*
*/
function configpath_to_value($configpath, $institution)
{
	global $config;

	$c = $config;

	foreach ( $configpath as $a )
	{
		if ($a == '$ID') { $a = $institution; }
		$c = $c[$a];
	}

	return $c;
}



/**
*
* Can a user perform an action on and institution
*
* note the fudge of 'combined' being treated as an institution
*
*/
function allow($institution, $action)
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

/**
*
* Returns a list of select elements for each institution for inclusion in a form
*
*/
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

/**
*
* Returns true if the institution has a configuration entry
*
*/
function valid_institution($institution)
{
	global $config;

	return array_key_exists($institution, $config["institutions"]);
}

/**
*
* returns true if the user has a configuration entry
*
*/
function valid_user($institution)
{
	global $config;

	return array_key_exists($institution, $config["users"]);
}

/**
*
* Converts a column heading into a comparevalue
*
*/
function heading_to_cmp($str)
{
	$str = preg_replace('/\s+/', '', $str);
	$str = strtolower($str);
	return $str;
}

/**
*
* Converts a comparevalue into a canonical heading
*
*/
function cmp_to_heading($cmpval)
{
	global $config;
	return $config["csv_input_columns_cmp"][$cmpval]['label'];

}


/**
*
* Send a 2D array to the downloader as a CSV file
*
*/
function send_csv($rows, $filename)
{
	// output headers so that the file is downloaded rather than displayed
	header('Content-Type: text/csv; charset=utf-8');
	header("Content-Disposition: attachment; filename=$filename");

	// create a file pointer connected to the output stream
	$output = fopen('php://output', 'w');

	write_csv_rows($rows, $output);

	fclose($output);

	log_event('EVENT',"$filename sent");
	exit;
}

/**
*
* Write a 2D array to a file
*
*/
function write_csv($rows, $filepath)
{
	if (($handle = fopen($filepath, "w")) !== FALSE) {
		write_csv_rows($rows, $handle);
		fclose($handle);
		log_event('EVENT',"$filename written");
	}
	else
	{
		exit_with_status(500,"Couldn't open $file_path for writing");
	}
}


/**
*
* Write a 2d array to a file handle
*
*/
function write_csv_rows($rows, $fh)
{
	foreach ($rows as $row)
	{
		fputcsv($fh, $row);
	}
}

/**
*
* For a given code, returns the string for the HTTP header
*
*/
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


/**
*
* Given an array of keys and an array of values, return an associative array
*
*/
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


##########################################################
#
#	System Functions
#
##########################################################

/**
*
* Returns the path to the combined file
*
*/
function aggregate_file()
{
	global $config;

	$path = $config["system"]["base_path"] . $config["system"]["combined_base"];

	create_dir_if_needed($path);

	return $path . 'active';
}

/**
*
* Given an institution ID, returns the path to the active file
*
*/
function institution_active_file($institution)
{
	global $config;

	$upload_dir = $config['institutions'][$institution]['upload_dir'];

	$target_file = $upload_dir . 'active';

	return $target_file;
}


/**
*
* Takes a directory path and creats it if it doesn't exist.
*
*/
function create_dir_if_needed($path)
{
	if (is_dir($path)) { return; }

	if (!mkdir($path,0770,1))
	{
		#don't log this event, just die.  Logging will cause recursion.
		$msg = "couldn't create $path\n";
		exit_with_status(500,$msg);
	}
}

/**
*
* Sends a file to the downloader
*
*/
function send_file($file, $filename, $mimetype = "text/csv")
{
	if (!file_exists($file))
	{
		exit_with_status(404,"Couldn't send $file -- not found");
	}
	header("Content-type: $mimetype");
	header('Content-Disposition: attachment; filename="'.$filename.'"');
	header("Content-Length: ". filesize($file));
	readfile($file);

}

/**
*
* Exit with an HTTP status code and a text message
*
*/
function exit_with_status($code, $message)
{
	header('Content-Type: text/plain; charset=utf-8');
	header(status_code_string($code),1,$code);
	echo $message;
	exit;
}

/**
*
* Return the full path of the cache file for a given cache ID, creating the directory if needed
*
*/
function cache_file($id)
{
	global $config;

	$path = $config['system']['base_path'] . $config['system']['cache_base'];

	create_dir_if_needed($path);

	$path .= $id;

	return $path;
}

/**
*
* Write an associative array to the disk
*
*/
function write_cache_file($id,$val)
{
	$file = cache_file($id);
	if (file_put_contents($file, serialize($val)) === false)
	{
		exit_with_status(500, "Couldn't open $path for put_file_contents");
	}
}

/**
*
* return an associative array to be used as a cache
*
*/
function read_cache_file($id)
{
	$file = cache_file($id);
	if (!file_exists($file))
	{
		#write an empty array to be read and returned
		write_cache_file($id, array());
	}

	$c = file_get_contents($file);
	if ($c === false)
	{
		exit_with_status(500, "Couldn't open $path for get_file_contents");
	}

	return unserialize($c);
}

/**
*
* Loads the JSON configuration file and converts it to a php array to be used as global $config constants.
*
* Also creates a comparevalue index for columns in submitted CSV files
*
*/
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
	foreach ($decoded["institutions"] as $id => $info)
	{
		$upload_dir = $upload_base . $id . '/';
		$decoded['institutions'][$id]["upload_dir"] = $upload_dir;
		create_dir_if_needed($upload_dir);
	}

	#create associative array of CSV columns based on compare values
	#create lists of requirement groups
	$decoded["csv_input_columns_cmp"] = array();
	$decoded["requirement_groups"] = array();
	foreach ($decoded["csv_input_columns"] as $heading => $info)
	{
		$compareval = heading_to_cmp($heading);
		if ($compareval == $heading)
		{
			#heading is compareval -- no need to do anything
			next;
		}

		if (array_key_exists($compareval,$decoded["csv_input_columns_cmp"]))
		{
			exit_with_status(500,"Column Heading Collision in configuration");
		}
		$decoded["csv_input_columns_cmp"][$compareval] = $info;
		$decoded["csv_input_columns_cmp"][$compareval]["label"] = $heading;

		if (array_key_exists('requirement_group',$info))
		{
			$decoded["requirement_groups"][$info['requirement_group']][] = $compareval;

		}
	}

	return $decoded;
}

/**
*
* Create an entry in the log file
*
*/
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

	$log_parts[] = $event_type;
	$log_parts[] = $_SERVER['REMOTE_USER'];
	$log_parts[] = date("Y-m-d H:i:s");
	$log_parts[] = $_SERVER['REMOTE_HOST'];
	$log_parts[] = $msg;

##need username too

	$log_line = implode("\t",$log_parts) . "\n";

	if (!fwrite($fp, $log_line))
	{
		die("Couldn't write to $log_file\n");
	}

	fclose($fp);
}

/**
*
* Given a newly uploaded file, archive the active file and replace with the new file
*
*/
#future work -- detect if the files are identical, and don't swap if they are
function swap_in_new_file($institution, $temp_file)
{
	global $config;

	$target_file = institution_active_file($institution);
	$upload_dir = $config['institutions'][$institution]['upload_dir'];

	if (file_exists($target_file))
	{
		$archive_filename = $upload_dir . 'archived-on-' . date("Y-m-d-H-i-s");
		if (!rename($target_file, $archive_filename)) {
			exit_with_status(500,"couldn't rename $target_file to $archive_filename");
		}
	}

	if (!rename($temp_file, $target_file)) {
		unlink($temp_file);
		exit_with_status(500,"couldn't rename $temp_file to $target_file");
	}
}

?>
