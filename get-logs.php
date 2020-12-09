<?php
$offset = \db_real_escape_string($_GET['start']);
$limit = \db_real_escape_string($_GET['length']);
$limitClause = "limit $limit offset $offset";

$columnName = 'count(1)';
$result = $module->queryLogs("select $columnName");
$row = db_fetch_assoc($result);
$totalRowCount = $row[$columnName];

$results = $module->queryLogs("
	select log_id, timestamp, message, details, failure
	order by log_id desc
	$limitClause
");

$rows = [];
while($row = $results->fetch_assoc()){
	$row['hasDetails'] = $row['details'] !== null;

	// It's a little much for the browser to load all the details into memory.
	// We now load them on the fly later instead.
	unset($row['details']);

	$rows[] = $row;
}

?>

{
	"draw": <?=$_GET['draw']?>,
	"recordsTotal": <?=$totalRowCount?>,
	"recordsFiltered": <?=$totalRowCount?>,
	"data": <?=json_encode($rows)?>
}