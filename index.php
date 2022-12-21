<?php
//ini_set('display_errors',1);            //错误信息
//ini_set('display_startup_errors',1);    //php启动错误信息
//error_reporting(-1);
error_reporting(0);

/**
 * Step 1: Require the Slim Framework using Composer's autoloader
 *
 * If you are not using Composer, you need to load Slim Framework with your own
 * PSR-4 autoloader.
 */
require 'vendor/autoload.php';

/** mysql **/
function connect_db() {
    $db_host="127.0.0.1";
    $db_user='root';
    $db_passwd="";
    $db_name="gateway";
    $db_port=13306;

    $mysqli = new mysqli($db_host, $db_user, $db_passwd, $db_name, $db_port);
    $mysqli->set_charset("utf8");
    return $mysqli;
}

function XML2JSON($xml) {

        function normalizeSimpleXML($obj, &$result) {
            $data = $obj;
            if (is_object($data)) {
                $data = get_object_vars($data);
            }
            if (is_array($data)) {
                foreach ($data as $key => $value) {
                    $res = null;
                    normalizeSimpleXML($value, $res);
                    if (($key == '@attributes') && ($key)) {
                        $result = $res;
                    } else {
                        $result[$key] = $res;
                    }
                }
            } else {
                $result = $data;
            }
        }
        normalizeSimpleXML($xml, $result);
        return json_encode($result,JSON_UNESCAPED_UNICODE);
        //return json_encode($result);
}
/**
 * Step 2: Instantiate a Slim application
 *
 * This example instantiates a Slim application using
 * its default settings. However, you will usually configure
 * your Slim application now by passing an associative array
 * of setting names and values into the application constructor.
 */
$app = new Slim\App();

/**
 * Step 3: Define the Slim application routes
 *
 * Here we define several Slim application routes that respond
 * to appropriate HTTP request methods. In this example, the second
 * argument for `Slim::get`, `Slim::post`, `Slim::put`, `Slim::patch`, and `Slim::delete`
 * is an anonymous function.
 */
$app->get('/', function ($request, $response, $args) {
    $response->write("Welcome to abf restful service!");
    return $response;
});

$app->get('/gateway/{sn}', function ($request, $response, $args) {
    //$args['sn']
    if(!isset($args["sn"]) ||strlen($args["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }
    $contentType = $request->getHeader('Accept')[0];
    try {
        $mysqli = connect_db();
        $query="select * from gateway where sn='".$args["sn"]."';";
        $result= $mysqli->query($query);
        if($result->num_rows <= 0) {
            $response = $response->withStatus(404);
            $result->close();
            return $response;
        }
        $xml=new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><data></data>');

        while($row = $result->fetch_object()) {
            $gw = $xml->addchild('gw');
            $gw->addAttribute('sn', $row->sn);
            $gw->addAttribute('name', $row->name);
            $gw->addAttribute('ver', $row->ver);
            $site = $gw->addchild('site');
            $site->addAttribute('name', $row->site_name);
            $site->addAttribute('longitude', $row->longitude);
            $site->addAttribute('latitude', $row->latitude);
            $owner = $gw->addchild('owner');
            $owner->addAttribute('name', $row->owner_name);
            $owner->addAttribute('desc', $row->owner_desc);
            $owner = $gw->addchild('room');
            $owner->addAttribute('name', $row->room_name);
            $owner->addAttribute('desc', $row->room_desc);
            $owner->addAttribute('camera', $row->room_camera);
        }
        $result->close();
    }
    catch (Exception $e)
    {
       $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }
    if(stristr($contentType, "application/xml") || stristr($contentType, "text/xml")) {
        $value = $xml->asXml();
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }else if(stristr($contentType, "application/json")|| stristr($contentType, "text/json")) {
        //xml to json
        $value = XML2JSON($xml->children()[0]);
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }else {
        $response = $response->withStatus(406);
    }
    return $response;
});


$app->post('/gateway', function ($request, $response, $args) {

    //$postvalue = $request->getBody();
    //$value=json_decode($postvalue,true);

    $input = $request->getParsedBody();
    $contentType = $request->getContentType();

    if(stristr($contentType, "application/xml")) {
        //xml to json
        $json = XML2JSON($input->children()[0]);
        $value = json_decode($json, TRUE);
        $response =$response->withHeader('Content-type', 'application/xml');
    }else if(stristr($contentType, "application/json")) {
        //do nothing
        $value = $input;
        $response =$response->withHeader('Content-type', 'application/json');
    }else {
        $response = $response->withStatus(406);
        return $response;
    }
    if(!isset($value["sn"]) ||strlen($value["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }

    try {
        $mysqli = connect_db();
        $query="replace into gateway(sn,name,ver,site_name,longitude,latitude,owner_name,owner_desc,room_name,room_desc,room_camera) values(?,?,?,?,?,?,?,?,?,?,?)";
        $result= $mysqli->prepare($query);
        $result->bind_param("ssssddsssss", $value["sn"],$value["name"],$value["ver"],$value["site"]["name"],$value["site"]["longitude"],
            $value["site"]["latitude"], $value["owner"]["name"], $value["owner"]["desc"], $value["room"]["name"],
            ($value["room"]["desc"]), ($value["room"]["camera"]));
        $result->execute();
        $result->close();
        $query="update downfile set status='0' where gwsn='".$value["sn"]."' and status='1'";
        $mysqli->query($query);
        //$response = $response->withStatus(201,'message');
        $response = $response->withStatus(201);
    }
    catch (Exception $e)
    {
        $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }


    return $response;
});

$app->get('/device/{sn}', function ($request, $response, $args) {
    if(!isset($args["sn"]) ||strlen($args["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }
    $contentType = $request->getHeader('Accept')[0];
    try {
        $mysqli = connect_db();
        $query="select * from device where gwsn='".$args["sn"]."';";
        $result= $mysqli->query($query);
        if($result->num_rows <= 0) {
            $response = $response->withStatus(404);
            $result->close();
            return $response;
        }
        $xml=new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?><data></data>');
        $json_sub=Array();
        while($row = $result->fetch_object()) {
            $device = $xml->addchild('device');
            $device->addAttribute('channel', $row->channel_id);
            $device->addAttribute('id', $row->device_id);
            $device->addAttribute('name', $row->device_name);
            $device->addAttribute('desc', $row->device_desc);
            array_push($json_sub, array('channel'=>$row->channel_id, 'id'=>$row->device_id, 'name'=>$row->device_name, 'desc'=>$row->device_desc));
        }
        $jsonstr=Array('device'=>$json_sub);
        $result->close();
    }
    catch (Exception $e)
    {
       $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }
    if(stristr($contentType, "application/xml") || stristr($contentType, "text/xml")) {
        $value = $xml->asXml();
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }else if(stristr($contentType, "application/json")|| stristr($contentType, "text/json")) {
        //xml to json
        //$value = XML2JSON($xml);
        $value = json_encode($jsonstr, JSON_UNESCAPED_UNICODE);
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }else {
        $response = $response->withStatus(406);
    }
    return $response;
});


$app->post('/device/{sn}', function ($request, $response, $args) {
    $input = $request->getParsedBody();
    $contentType = $request->getContentType();

    if(stristr($contentType, "application/xml")) {
        //xml to json
        $json = XML2JSON($input);
        $value = json_decode($json, TRUE);
        $response =$response->withHeader('Content-type', 'application/xml');
    }else if(stristr($contentType, "application/json")) {
        //do nothing
        $value = $input;
        $response =$response->withHeader('Content-type', 'application/json');
    }else {
        $response = $response->withStatus(406);
        return $response;
    }
    if(!isset($args["sn"]) ||strlen($args["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }
    try {
        $mysqli = connect_db();
        $query="delete from device where gwsn='".$args["sn"]."';";
        $mysqli->query($query);
        $query="replace into device(gwsn,channel_id,device_id,device_name,device_desc) values(?,?,?,?,?)";
        $result= $mysqli->prepare($query);
        if(is_array($value["device"][0])) {
            foreach( $value["device"] as $device) {
                $result->bind_param("siiss", $args["sn"],$device["channel"],$device["id"],$device["name"],$device["desc"]);
                $result->execute();
            }
        }
        else
        {
            $device = $value["device"];
            $result->bind_param("siiss", $args["sn"],$device["channel"],$device["id"],$device["name"],$device["desc"]);
            //$result->bind_param("siiss", $args["sn"],$value["channel"],$value["id"],$value["name"],$value["desc"]);
            $result->execute();
        }
        $result->close();
        $response = $response->withStatus(201);
    }
    catch (Exception $e)
    {
        $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }


    return $response;
});

$app->post('/realdata/{sn}', function ($request, $response, $args) {
    $blankstr="";
    $input = $request->getParsedBody();
    $contentType = $request->getContentType();
    if(stristr($contentType, "application/xml")) {
        //xml to json
        $json = XML2JSON($input);
        $value = json_decode($json, TRUE);
        $response =$response->withHeader('Content-type', 'application/xml');
    }else if(stristr($contentType, "application/json")) {
        //do nothing
        $value = $input;
        $response =$response->withHeader('Content-type', 'application/json');
    }else {
        $response = $response->withStatus(406);
        return $response;
    }
    if(!isset($args["sn"]) ||strlen($args["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }
    try {
        $mysqli = connect_db();
        $query="replace into realdata(gwsn,channel_id,device_id,log_dt,db_link,node_name,node_value, node_unit) values(?,?,?,?,?,?,?,?)";
        $result= $mysqli->prepare($query);
        if(is_array($value["device"][0])) {
            foreach( $value["device"] as $device) {
                if(is_array($device["node"][0])) {
                    foreach( $device["node"] as $node) {
                        if(isset($node["dblink"])) {
                            $result->bind_param("siisssds", $args["sn"],$device["channel"],$device["id"],$value["logdt"],$node["dblink"],$node["name"],$node["value"], $node["unit"]);
                        } else {
                            $result->bind_param("siisssds", $args["sn"],$device["channel"],$device["id"],$value["logdt"],$blankstr,$node["name"],$node["value"], $node["unit"]);
                        }
                        $result->execute();
                    }
                }
                else {
                    $node = $device["node"];
                    if(isset($node["dblink"])) {
                        $result->bind_param("siisssds", $args["sn"],$device["channel"],$device["id"],$value["logdt"],$node["dblink"],$node["name"],$node["value"], $node["unit"]);
                    } else {
                        $result->bind_param("siisssds", $args["sn"],$device["channel"],$device["id"],$value["logdt"],$blankstr,$node["name"],$node["value"], $node["unit"]);
                    }
                    $result->execute();
                }
            }
        }
        else {
            $device = $value["device"];
            if(is_array($device["node"][0])) {
                foreach( $device["node"] as $node) {
                    if(isset($node["dblink"])) {
                        $result->bind_param("siisssds", $args["sn"],$device["channel"],$device["id"],$value["logdt"],$node["dblink"],$node["name"],$node["value"], $node["unit"]);
                    } else {
                        $result->bind_param("siisssds", $args["sn"],$device["channel"],$device["id"],$value["logdt"],$blankstr,$node["name"],$node["value"], $node["unit"]);
                    }
                    $result->execute();
                }
            }
            else {
                $node = $device["node"];
                if(isset($node["dblink"])) {
                    $result->bind_param("siisssds", $args["sn"],$device["channel"],$device["id"],$value["logdt"],$node["dblink"],$node["name"],$node["value"], $node["unit"]);
                } else {
                    $result->bind_param("siisssds", $args["sn"],$device["channel"],$device["id"],$value["logdt"],$blankstr,$node["name"],$node["value"], $node["unit"]);
                }
                $result->execute();
            }
        }
        $result->close();
        $query = "select * from downfile where gwsn='".$args["sn"]."' and status = '0'";
        $result= $mysqli->query($query);
        if($result->num_rows <= 0) {
            $response = $response->withStatus(201);
            $result->close();
        }
        else
        {
            $response = $response->withStatus(426);
            $result->close();
        }

    }
    catch (Exception $e)
    {
print($e);
        $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }


    return $response;
});

$app->post('/alarmdata/{sn}', function ($request, $response, $args) {
    $blankstr="";
    $input = $request->getParsedBody();
    $contentType = $request->getContentType();

    if(stristr($contentType, "application/xml")) {
        //xml to json
        $json = XML2JSON($input);
        $value = json_decode($json, TRUE);
        $response =$response->withHeader('Content-type', 'application/xml');
    }else if(stristr($contentType, "application/json")) {
        //do nothing
        $value = $input;
        $response =$response->withHeader('Content-type', 'application/json');
    }else {
        $response = $response->withStatus(406);
        return $response;
    }
    if(!isset($args["sn"]) ||strlen($args["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }
    try {
        $mysqli = connect_db();
        $query="replace into alarmdata(gwsn,channel_id,device_id,log_dt,db_link,node_name,node_value, node_unit, threshold_value, type, flag) values(?,?,?,?,?,?,?,?,?,?,?)";
        $result= $mysqli->prepare($query);
        if(is_array($value["node"][0])) {
            foreach( $value["node"] as $node) {
                if(isset($node["dblink"])) {
                    $ret = $result->bind_param("siisssdsdii", $args["sn"],$node["channel"],$node["device"],$node["logdt"],$node["dblink"],$node["name"],$node["value"], $node["unit"], $node["threshold_value"], $node["type"], $node["flag"]);
                } else {
                    $ret = $result->bind_param("siisssdsdii", $args["sn"],$node["channel"],$node["device"],$node["logdt"],$blankstr,$node["name"],$node["value"], $node["unit"], $node["threshold_value"], $node["type"], $node["flag"]);
                }
                $result->execute();
            }
        }
        else {
            $node = $value["node"];
                if(isset($node["dblink"])) {
                    $ret = $result->bind_param("siisssdsdii", $args["sn"],$node["channel"],$node["device"],$node["logdt"],$node["dblink"],$node["name"],$node["value"], $node["unit"], $node["threshold_value"], $node["type"], $node["flag"]);
                } else {
                    $ret = $result->bind_param("siisssdsdii", $args["sn"],$node["channel"],$node["device"],$node["logdt"],$blankstr,$node["name"],$node["value"], $node["unit"], $node["threshold_value"], $node["type"], $node["flag"]);
                }
            $result->execute();
        }
        $result->close();
        $response = $response->withStatus(201);
    }
    catch (Exception $e)
    {
        $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }


    return $response;
});

$app->get('/calldata/{sn}', function ($request, $response, $args) {
    if(!isset($args["sn"]) ||strlen($args["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }
    $contentType = $request->getHeader('Accept')[0];
    try {
        $mysqli = connect_db();
        $query="select * from recall where gwsn='".$args["sn"]."';";
        $result= $mysqli->query($query);
        if($result->num_rows <= 0) {
            $response = $response->withStatus(404);
            $result->close();
            return $response;
        }
        $xml=new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?> <data></data>');

        $json_sub=Array();
        while($row = $result->fetch_object()) {
            $device = $xml->addchild('node');
            $device->addAttribute('startdt', $row->starttime);
            $device->addAttribute('enddt', $row->endtime);
            array_push($json_sub, array('startdt'=>$row->starttime, 'enddt'=>$row->endtime));
        }
        $jsonstr=Array('node'=>$json_sub);
        $result->close();
    }
    catch (Exception $e)
    {
       $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }
    if(stristr($contentType, "application/xml") || stristr($contentType, "text/xml")) {
        $value = $xml->asXml();
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }else if(stristr($contentType, "application/json")|| stristr($contentType, "text/json")) {
        //xml to json
        //$value = XML2JSON($xml);
        $value = json_encode($jsonstr, JSON_UNESCAPED_UNICODE);
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }else {
        $value = $xml->asXml();
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }
    return $response;
});

$app->delete('/calldata/{sn}', function ($request, $response, $args) {
    $input = $request->getParsedBody();
    $contentType = $request->getContentType();
    if(stristr($contentType, "application/xml")) {
        //xml to json
        $json = XML2JSON($input);
        $value = json_decode($json, TRUE);
        $response =$response->withHeader('Content-type', 'application/xml');
    }else if(stristr($contentType, "application/json")) {
        //do nothing
        $value = $input;
        $response =$response->withHeader('Content-type', 'application/json');
    }else {
        $response = $response->withStatus(406);
        return $response;
    }

    if(!isset($args["sn"]) ||strlen($args["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }

    try {
        $mysqli = connect_db();
        if(is_array($value["node"][0])) {
            foreach( $value["node"] as $node) {
                $query="delete from recall where gwsn='".$args["sn"]."' and starttime='".$node["startdt"]."' and endtime='".$node["enddt"]."'";
                $result= $mysqli->query($query);
            }
        }
        else {
            $node = $value["node"];
            $query="delete from recall where gwsn='".$args["sn"]."' and starttime='".$node["startdt"]."' and endtime='".$node["enddt"]."'";
            $result= $mysqli->query($query);
        }
        $response = $response->withStatus(204);
    }
    catch (Exception $e)
    {
        $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }

    return $response;
});

$app->put('/downfile/{sn}', function ($request, $response, $args) {
    $input = $request->getParsedBody();
    $contentType = $request->getContentType();
    if(stristr($contentType, "application/xml")) {
        //xml to json
        $json = XML2JSON($input);
        $value = json_decode($json, TRUE);
        $response =$response->withHeader('Content-type', 'application/xml');
    }else if(stristr($contentType, "application/json")) {
        //do nothing
        $value = $input;
        $response =$response->withHeader('Content-type', 'application/json');
    }else {
        $response = $response->withStatus(406);
        return $response;
    }

    if(!isset($args["sn"]) ||strlen($args["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }

    try {
        $mysqli = connect_db();
        if(is_array($value["node"][0])) {
            foreach( $value["node"] as $node) {
                $query="update downfile set status='".$node["status"]."' where gwsn='".$args["sn"]."' and filemd5='".$node["md5"]."'";
                $result= $mysqli->query($query);
            }
        }
        else {
            $node = $value["node"];
            $query="update downfile set status='".$node["status"]."' where gwsn='".$args["sn"]."' and filemd5='".$node["md5"]."'";
            $result= $mysqli->query($query);
        }
        $response = $response->withStatus(201);
    }
    catch (Exception $e)
    {
        $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }

    return $response;
});

$app->get('/downfile/{sn}', function ($request, $response, $args) {
    if(!isset($args["sn"]) ||strlen($args["sn"]) < 12)
    {
        $response = $response->withStatus(406);
        return $response;
    }
    $contentType = $request->getHeader('Accept')[0];
    try {
        $mysqli = connect_db();
        $query="select * from downfile where gwsn='".$args["sn"]."' and status='0'";
        $result= $mysqli->query($query);
        if($result->num_rows <= 0) {
            $response = $response->withStatus(404);
            $result->close();
            return $response;
        }
        $xml=new SimpleXMLElement('<?xml version="1.0" encoding="utf-8"?> <data></data>');

        $json_sub=Array();
        while($row = $result->fetch_object()) {
            $device = $xml->addchild('node');
            $device->addAttribute('file', $row->filename);
            $device->addAttribute('md5', $row->filemd5);
            $device->addAttribute('type', $row->filetype);
            $device->addAttribute('path', $row->gwpath);
            array_push($json_sub, array('file'=>$row->filename, 'md5'=>$row->filemd5, 'type'=>$row->filetype, 'path'=>$row->gwpath));
        }
        $jsonstr=Array('node'=>$json_sub);
        $result->close();
    }
    catch (Exception $e)
    {
       $response = $response->withStatus(406);
    }
    finally {
        $mysqli->close();
    }
    if(stristr($contentType, "application/xml") || stristr($contentType, "text/xml")) {
        $value = $xml->asXml();
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }else if(stristr($contentType, "application/json")|| stristr($contentType, "text/json")) {
        //xml to json
        //$value = XML2JSON($xml);
        $value = json_encode($jsonstr, JSON_UNESCAPED_UNICODE);
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/json; charset=UTF-8');
    }else {
        $value = $xml->asXml();
        $response->write($value);
        $response =$response->withHeader('Content-Type', 'application/xml; charset=UTF-8');
    }
    return $response;
});

/**
 * Step 4: Run the Slim application
 *
 * This method should be called last. This executes the Slim application
 * and returns the HTTP response to the HTTP client.
 */
$app->run();
