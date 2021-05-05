<?php

require_once('db.php');
require_once('../model/Response.php');
require_once('../model/image.php');

function sendResponse($statusCode,$success,$message = null,$toCache = false, $data = null){
    $response = new Response();
    $response->setHttpStatusCode($statusCode);
    $response->setSuccess($success);
    
    if($message != null){
        $response->addMessage($message);
    }
    $response->toCache($toCache);

    if($data != null){
        $response->setData($data);
    }
    $response->send();
    exit;
}

function uploadImageRoute($readDB,$writeDB,$taskid,$returned_userid){
    try{
        if(!isset($_SERVER['CONTENT_TYPE']) || strpos($_SERVER['CONTENT_TYPE'],"multipart/form-data; boundary=") === false){
            sendResponse(400,false,"content type header is not set to multipart/form-data with a boundary");
        } 
        
        $query = $writeDB->prepare('select id from tbltasks where id = :taskid and userid = :userid');
        $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->execute();
        

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            sendResponse(404,false,"Task  Not found");
        }

        if(!isset($_POST['attributes'])){
            sendResponse(400,false,"Attribute missing from body of request");
        }
        
        $jsonImageAttributes = json_decode($_POST['attributes']);

        if(!$jsonImageAttributes){
            sendResponse(400,false,"Attribute field is not valid json");
        }

        if(!isset($jsonImageAttributes->title) || !isset($jsonImageAttributes->filename) || $jsonImageAttributes->title == '' || $jsonImageAttributes->filename == ''){
            sendResponse(400,false,"Title and filename fields are mandatory");
        }

        if(strpos($jsonImageAttributes->filename,".") > 0 ){
            sendResponse(400,false,"filename must not contain file extension");
        }
        
        if(!isset($_FILES['imagefile']) || $_FILES['imagefile']['error'] !==0){
            sendResponse(500,false,"Image file upload unsuccessful - make sure you selected a file");
        }

        $imagefileDetails = getimagesize($_FILES['imagefile']['tmp_name']);
        
        if(!isset($_FILES['imagefile']['size']) && $_FILES['imagefile']['size'] > 5242880){
            sendResponse(400,false,"file must be under 5MB");
        }
        $allowedImageFileTypes = array('image/jpeg','image/gif','image/png');

        if(!in_array($imagefileDetails['mime'],$allowedImageFileTypes)){
            sendResponse(400,false,"file type not supported");
        }

        $fileExtension = "";
        switch($imagefileDetails['mime']){
            case "image/jpeg": $fileExtension = ".jpg";break;
            case "image/gif":  $fileExtension = ".gif";break;
            case "image/png":  $fileExtension = ".png";break;
            default:           break;
        }

        if($fileExtension == ""){
            sendResponse(400,false,"No valid file extension found for mimetype ");
        }

        $image = new Image(null, $jsonImageAttributes->title, $jsonImageAttributes->filename.$fileExtension,$imagefileDetails['mime'],$taskid);

        $title = $image->getTitle();
        $newFilename = $image->getFilename();
        $mimetype = $image->getMimetype();

        $query = $readDB->prepare("SELECT tblimages.id FROM tblimages, tbltasks WHERE tblimages.taskid = tbltasks.id AND tbltasks.id = :taskid AND tbltasks.userid = :userid AND tblimages.filename = :filename");
        $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->bindParam(':filename',$newFilename,PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();
        if($rowCount != 0){
            sendResponse(409,false,"A file with that filename already exists for this task - try a different filename");
        }

        $writeDB->beginTransaction();
        
        $query = $writeDB->prepare("INSERT INTO tblimages (title, filename, mimetype, taskid) VALUES (:title, :filename, :mimetype, :taskid)");
        $query->bindParam(':title',$title,PDO::PARAM_STR);
        $query->bindParam(':filename',$newFilename,PDO::PARAM_STR);
        $query->bindParam(':mimetype',$mimetype,PDO::PARAM_STR);
        $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if($rowCount == 0){
           if($writeDB->inTransaction()){
                $writeDB->rollback();
            }
            sendResponse(500,false,"failed to upload image");  
        }

        $lastImageId = $writeDB->lastInsertId();

        $writeDB->commit();

        $query = $readDB->prepare("SELECT tblimages.id, tblimages.title, tblimages.filename, tblimages.mimetype, tblimages.taskid FROM tblimages, tbltasks WHERE tblimages.id={$lastImageId} AND tbltasks.id={$taskid} AND tbltasks.userid={$returned_userid} AND tblimages.taskid={$taskid}");
        $query->bindParam(':imageid',$lastImageId,PDO::PARAM_INT);
        $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if($rowCount == 0){
            if($writeDB->inTransaction()){
                $writeDB->rollback();
            } 
            sendResponse(500,false,"failed to retrieve image attributes after upload - try uploadng image again");  
        }

        $imageArray = array();
        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row['id'],$row['title'],$row['filename'],$row['mimetype'],$row['taskid']);
            $imagearray = $image->returnImageasArray();
        }

        $image->saveImageFile($_FILES['imagefile']['tmp_name']);

        //$writeDB->commit();

        sendResponse(200,true,"Image uploaded successfully",false,$imageArray);

    }
    catch(PDOException $ex){
        error_log("Database query Error -".$ex, 0);
        if($writeDB->inTransaction()){
            $writeDB->rollback();
        }
        print_r($ex->getTrace());
        sendResponse(500,false,"Database connection error 157");
    }
    catch(ImageException $ex){
        if($writeDB->inTransaction()){
            $writeDB->rollback();
        }
        sendResponse(500,false, $ex->getMessage());
    }
}
function getImageAttributes($readDB,$taskid,$imageid,$returned_userid){
    try{

        $query = $readDB->prepare('select tblimages.id,tblimages.title,tblimages.filename,tblimages.mimetype,tblimages.taskid from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid  and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
        $query->bindParam(':imageid',$imageid,PDO::PARAM_INT);
        $query->bindParam(':taskid', $taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->execute();
    
        $rowCount = $query->rowCount();

        if($rowCount === 0){
            sendResponse(400,false,"Image not found");
        }
        $imageArray = array();

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row['id'],$row['title'],$row['filename'],$row['mimetype'],$row['taskid']);
            $imageArray[] = $image->returnImageasArray();
        }
        sendResponse(200,true,null,true,$imageArray);

    
    }
    catch(ImageException $ex){
        sendResponse(500,false,$ex->getMessage());
    }
    catch(PDOException $ex){
        error_log(" Database query Error -".$ex, 0);  
        sendResponse(500,false,"failed to get image attributes");
    }
}
function getImageroute($readDB,$taskid,$imageid,$returned_userid){
    try{
        $query = $readDB->prepare('select tblimages.id,tblimages.title,tblimages.filename,tblimages.mimetype,tblimages.taskid from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid  and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
        $query->bindParam(':imageid',$imageid,PDO::PARAM_INT);
        $query->bindParam(':taskid', $taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->execute();
    
        $rowCount = $query->rowCount();

        if($rowCount === 0){
            sendResponse(404,false,"Image not found");
        }

        $image = null;

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row['id'],$row['title'],$row['filename'],$row['mimetype'],$row['taskid']);
        }

        if($image == null){
            sendResponse(500,false,"Image not found");
        }

        $image->returnImageFile();

    }
    catch(ImageException $ex){
        sendResponse(500,false,$ex->getMessage());
    }
    catch(PDOException $ex){
        error_log(" Database query Error -".$ex, 0);  
        sendResponse(500,false,"error getting image");
    }
}
function updateImageAttributesRoute($writeDB,$taskid,$imageid,$returned_userid){
    try{
        if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
            sendResponse(400,false,"content type header is not set JSON");
        } 

        $rawPatchData =  file_get_contents('php://input');
        if(!$jsonData = json_decode($rawPatchData)){
            sendResponse(400,false,"request body  is not valid JSON");
        }
        
        $title_updated = false;
        $filename_updated = false;

        $queryFields = "";
        if(isset($jsonData->title)){
            $title_updated = true;
            $queryFields .= "tblimages.title = :title,";
        }
        if(isset($jsonData->filename)){
            if(strpos($jsonData->filename, ".") !== false){
                sendResponse(400,false,"filename cannot contain any dots or file extensions");
            }
            $filename_updated = true;
            $queryFields .= "tblimages.filename = :filename,";

        }
        $queryFields = rtrim($queryFields, "," );

        if($title_updated === false && $filename_updated === false){
            sendResponse(400,false,"No image fields provided");
        }

        $writeDB->beginTransaction();
        $query = $writeDB->prepare('select tblimages.* from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid  and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
        $query->bindParam(':imageid',$imageid,PDO::PARAM_INT);
        $query->bindParam(':taskid', $taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->execute();
    
        $rowCount = $query->rowCount();

        if($rowCount === 0){
            if($writeDB->inTransaction()){
                $writeDB->rollback();
            }
            sendResponse(404,false,"Image not found to update");
        }

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row['id'],$row['title'],$row['filename'],$row['mimetype'],$row['taskid']);
        }

        $queryString = "UPDATE tblimages INNER JOIN tbltasks ON tblimages.taskid = tbltasks.id SET {$queryFields} WHERE tblimages.id = :imageid AND tblimages.taskid = tbltasks.id AND tblimages.taskid = :taskid AND tbltasks.userid = :userid";
        $query = $writeDB->prepare($queryString);
        
        if($title_updated ===true){
            $image->setTitle($jsonData->title);
            $up_title = $image->getTitle();
            $query->bindParam('title',$up_title,PDO::PARAM_STR);
        }
        if($filename_updated ===true){
            $originalFilename = $image->getFilename();
            $image->setFilename($jsonData->filename.".".$image->getFileExtention());
            $up_filename = $image->getFilename();
            $query->bindParam(':filename',$up_filename,PDO::PARAM_STR);
        }

        $query->bindParam(':imageid',$imageid,PDO::PARAM_INT);
        $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if($rowCount == 0){
            if($writeDB->inTransaction()){
                $writeDB->rollback();
            }
            sendResponse(400,false,"image attributes not updated -the given values may be same as the stored values"); 
        }
        
        $query = $writeDB->prepare('SELECT tblimages.id,tblimages.title,tblimages.filename,tblimages.mimetype,tblimages.taskid FROM tblimages, tbltasks WHERE tblimages.id = :imageid AND tbltasks.id = :taskid AND tbltasks.userid = :userid AND tbltasks.id = tblimages.taskid');
        $query->bindParam(':imageid',$imageid,PDO::PARAM_INT);
        $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        if($rowCount === 0){
            if($writeDB->inTransaction()){
                $writeDB->rollback();
            }
            sendResponse(404,false," No image found");
        }

        $imageArray = array();
        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row['id'],$row['title'],$row['filename'],$row['mimetype'],$row['taskid']);
            $imageArray[] = $image->returnImageasArray();
        }

        if($filename_updated === true){
            $image->renameImageFile($originalFilename,$up_filename);
        }

        $writeDB->commit();

        sendResponse(200 ,true, "Image attributes updated",false,$imageArray);

    }
    catch(PDOException $ex){
        error_log("Database query Error".$ex, 0); 
        if($writeDB->inTransaction()){
            $writeDB->rollback();
        }
        sendResponse(500,false,"failed to update image attributes - check your data for errors 349");
    }
    catch(ImageException $ex){ 
        if($writeDB->inTransaction()){
            $writeDB->rollback();
        }
        sendResponse(400,false,$ex->getMessage());
    }
}
function deleteImageroute($writeDB,$taskid,$imageid,$returned_userid){
    try{
        $writeDB->begintransaction();

        $query = $writeDB->prepare('select tblimages.* from tblimages, tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
        $query->bindParam(':imageid',$imageid,PDO::PARAM_INT);
        $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        
        if($rowCount === 0){
            $writeDB->rollback();
            sendResponse(404,false,"image not found");
        }

        $image = null;

        while($row = $query->fetch(PDO::FETCH_ASSOC)){
            $image = new Image($row['id'],$row['title'],$row['filename'],$row['mimetype'],$row['taskid']);
            $imageArray[] = $image->returnImageasArray();
        }

        if($image == null){
            $writeDB->rollback();
            sendResponse(500,false,"Failed to get image");
        }

        $query = $writeDB->prepare('delete tblimages from tblimages,tbltasks where tblimages.id = :imageid and tbltasks.id = :taskid and tblimages.taskid = tbltasks.id and tbltasks.userid = :userid');
        $query->bindParam(':imageid',$imageid,PDO::PARAM_INT);
        $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
        $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
        $query->execute();

        $rowCount = $query->rowCount();
        
        if($rowCount === 0){
            $writeDB->rollback();
            sendResponse(404,false,"image not found");
        }
        $image->deleteImageFile();

        $writeDB->commit();

        sendResponse(200,true,"image deleted");

    }
    catch(PDOException $ex){
        error_log("Database query Error".$ex, 0);
        $writeDB->rollback(); 
        print_r($ex->getTrace());
        sendResponse(500,false,"failed to delete image");
    }
    catch(ImageException $ex){ 
        $writeDB->rollback();
        sendResponse(400,false,$ex->getMessage());
    }
}
function checkAuthStatusAndReturnUserID($writeDB){

    if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){
        $message = null;
        if(!isset($_SERVER['HTTP_AUTHORIZATION'])){
            $message = "Access token is missing from the header";
        }else{
            if(strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){
               $message = "Access token cannnot be blank";
            }
        }
        sendResponse(401,false,$message);
    }

    $accesstoken = $_SERVER['HTTP_AUTHORIZATION'];
    try{
        $query = $writeDB->prepare('select userid,accesstokenexpiry,useractive,loginattempts from tblsessions, tblusers  where tblsessions.userid = tblusers.id and accesstoken =:accesstoken');
        $query->bindParam(':accesstoken',$accesstoken,PDO::PARAM_STR);
        $query->execute();

        $rowCount = $query->rowCount();

        if($rowCount === 0){
            sendResponse(400,false,"Invalid Access token");
        }

        $row = $query->fetch(PDO::FETCH_ASSOC);

        $returned_userid = $row['userid'];
        $returned_accesstokenexpiry = $row['accesstokenexpiry'];
        $returned_useractive = $row['useractive'];
        $returned_loginattempts = $row['loginattempts'];


        if($returned_useractive !== 'Y' ){
            sendResponse(401,false,"user account is not active");
        }
        if($returned_loginattempts >= 3){
            sendResponse(401,false,"User account  is currently locked out"); 
        }

        if(strtotime($returned_accesstokenexpiry) < time()){
            sendResponse(401,false,"Access token has expired");
        }
        return $returned_userid;
    }
    catch(PDOException $ex){
        sendResponse(500,false,"There was an issue authenticating access Token - please try again");
    }
}
try{
    $writeDB = DB::connectionWriteDB();
    $readDB = DB::connectionReadDb();
}
catch(PDOException $ex){
    error_log("connection Error -".$ex, 0);
    sendResponse(500,false,"Database connection error 226");
}

$returned_userid = checkAuthStatusAndReturnUserID($writeDB);

if(array_key_exists("taskid",$_GET) && array_key_exists("imageid",$_GET) && array_key_exists("attributes",$_GET)){
    $taskid = $_GET['taskid'];
    $imageid = $_GET['imageid'];
    $attributes = $_GET['attributes'];

    if($imageid == '' || !is_numeric($imageid) || $taskid == '' || !is_numeric($taskid)){
        sendResponse(400,false,"Imageid or Taskid cannot be blank or must be numeric");
    }

    if($_SERVER['REQUEST_METHOD'] === 'GET'){
        getImageAttributes($readDB,$taskid,$imageid,$returned_userid);

    }elseif($_SERVER['REQUEST_METHOD'] === 'PATCH'){
        updateImageAttributesRoute($writeDB,$taskid,$imageid,$returned_userid);
    }else{
        sendResponse(405,false,"Request Method Not Allowed");
    }
}elseif(array_key_exists("taskid",$_GET) && array_key_exists("imageid",$_GET)){
    $taskid = $_GET['taskid'];
    $imageid = $_GET['imageid'];
    
    if($imageid == '' || !is_numeric($imageid) || $taskid == '' || !is_numeric($taskid)){
        sendResponse(400,false,"Imageid or Taskid cannot be blank or must be numeric");
    }

    if($_SERVER['REQUEST_METHOD'] === 'GET'){
        getImageroute($readDB,$taskid,$imageid,$returned_userid);
    }
    elseif($_SERVER['REQUEST_METHOD'] === 'DELETE'){
        deleteImageroute($writeDB,$taskid,$imageid,$returned_userid);
    }
    else{
        sendResponse(405,false,"Request Method Not Allowed");
    }
}
elseif(array_key_exists("taskid",$_GET)){
    $taskid = $_GET['taskid'];
    if($taskid == '' || !is_numeric($taskid)){
        sendResponse(400,false,"Taskid cannot be blank or must be numeric");
    }
    if($_SERVER['REQUEST_METHOD'] === 'POST'){
        uploadImageRoute($readDB, $writeDB, $taskid, $returned_userid);

    }else{
        sendResponse(405,false,"Request Method Not Allowed");
    }
}
else{
    sendResponse(404,false,"Endpoint not found");
}
?>