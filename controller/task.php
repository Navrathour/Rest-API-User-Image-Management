<?php

require_once('db.php');
require_once('../model/Task.php');
require_once('../model/Response.php');
require_once('../model/image.php');

function retrieveTaskImages($dbConn,$taskid,$returned_userid){
    $imageQuery = $dbConn->prepare('SELECT tblimages.* from tblimages, tbltasks where tbltasks.id = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
    $imageQuery->bindParam(':taskid',$taskid,PDO::PARAM_INT);
    $imageQuery->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
    $imageQuery->execute();

    $imageArray = array();

    while($imageRow = $imageQuery->fetch(PDO::FETCH_ASSOC)){
        $image = new Image($imageRow['id'],$imageRow['title'],$imageRow['filename'],$imageRow['mimetype'],$imageRow['taskid']);
        $imageArray[] = $image->returnImageasArray();
    }
    return $imageArray;
}

try{
    $writeDB = DB::connectionWriteDB();
    $readDB  = DB::connectionReadDb(); 
}
catch(PDOException $ex){
    error_log("Connection error -".$ex,0);
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database connection error");
    $response->send();
    exit();
}

//begin auth script
if(!isset($_SERVER['HTTP_AUTHORIZATION']) || strlen($_SERVER['HTTP_AUTHORIZATION']) < 1){
    $response = new Response();
    $response->setHttpStatusCode(401);
    $response->setSuccess(false);
    if(!isset($_SERVER['HTTP_AUTHORIZATION'])){
        $response->addMessage("Access token is missing from the header");
    }elseif(isset($_SERVER['HTTP_AUTHORIZATION']) && (strlen($_SERVER['HTTP_AUTHORIZATION']) < 1) ){
        $response->addMessage("Access token cannnot be blank");
    }
    $response->send();
    exit; 
}

$accesstoken = $_SERVER['HTTP_AUTHORIZATION'];
try{
    $query = $writeDB->prepare('select userid,accesstokenexpiry,useractive,loginattempts from tblsessions, tblusers  where tblsessions.userid = tblusers.id and accesstoken =:accesstoken');
    $query->bindParam('accesstoken',$accesstoken,PDO::PARAM_STR);
    $query->execute();

    $rowCount = $query->rowCount();

    if($rowCount === 0){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Invalid Access token");
        $response->send();
        exit;
    }

    $row = $query->fetch(PDO::FETCH_ASSOC);

    $returned_userid = $row['userid'];
    $returned_accesstokenexpiry = $row['accesstokenexpiry'];
    $returned_useractive = $row['useractive'];
    $returned_loginattempts = $row['loginattempts'];

    if($returned_useractive !== 'Y' ){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("user account is not active");
        $response->send();
        exit; 
    }
    if($returned_loginattempts >= 3){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("User account  is currently locked out");
        $response->send();
        exit; 
    }

    if(strtotime($returned_accesstokenexpiry) < time()){
        $response = new Response();
        $response->setHttpStatusCode(401);
        $response->setSuccess(false);
        $response->addMessage("Access token has expired");
        $response->send();
        exit; 
    }
}
catch(PDOException $ex){
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("There was an issue authenticating access Token - please try again");
    $response->send();
    exit; 
}
//end auth script

if(array_key_exists("taskid",$_GET)){
    $taskid = $_GET['taskid'];
    if( $taskid == ''|| !is_numeric($taskid)){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Task ID cannot be blank or must be numeric");
        $response->send();
        exit;  
    }
    if($_SERVER['REQUEST_METHOD'] === 'GET'){
        try{
            $query = $readDB->prepare('select id,title, description ,DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") as deadline,completed from tbltasks where id = :taskid and userid = :userid'); 
            $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
            $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
            $query->execute();
            $rowCount = $query->rowCount();

            $taskArray = array();
            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit;
            }
            $imageArray = array();
            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $imageArray = retrieveTaskImages($readDB,$taskid,$returned_userid);
                $task = new Task($row['id'],$row['title'],$row['description'],$row['deadline'],$row['completed'],$imageArray);
                $taskArray[] = $task->returnTaskASArray();
            }
            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;
            $response = new Response();
            
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit;
        }
        catch(ImageException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(PDOException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(PDOException $ex){
            error_log("Database query error -".$ex,0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("failed to get task");
            $response->send();
            exit;  
        }
    }    
    elseif($_SERVER['REQUEST_METHOD'] === 'DELETE'){
        try{

            $imageSelectQuery = $readDB->prepare('select tblimages.* from tblimages, tbltasks where tbltasks.id = :taskid  and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
            $imageSelectQuery->bindParam(':taskid', $taskid,PDO::PARAM_INT);
            $imageSelectQuery->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
            $imageSelectQuery->execute();

            while($imageRow = $imageSelectQuery->fetch(PDO::FETCH_ASSOC)){
                $writeDB->begintransaction();

                $image = new Image($imageRow['id'],$imageRow['title'],$imageRow['filename'],$imageRow['mimetype'],$imageRow['taskid']);
                $imageid = $image->getID();

                $query = $writeDB->prepare('delete tblimages from tblimages ,tbltasks where tblimages.id = :imageid and  tblimages.taskid = :taskid and tbltasks.userid = :userid and tblimages.taskid = tbltasks.id');
                $query->bindParam(':imageid', $imageid,PDO::PARAM_INT);
                $query->bindParam(':taskid', $taskid,PDO::PARAM_INT);
                $query->bindParam(':userid', $returned_userid,PDO::PARAM_INT);
                $query->execute();

                $image->deleteImageFile();
                $writeDB->commit();
            }
    
            $query = $writeDB->prepare('DELETE from tbltasks where id = :taskid and userid = :userid');
            $query->bindParam('taskid',$taskid,PDO::PARAM_INT);
            $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
            $query->execute();
            $rowCount = $query->rowCount();
            if($rowCount == 0){
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("Task not found");
                $response->send();
                exit;
            }

            $taskImageFolder = "./../../taskimages/".$taskid;

            if(is_dir($taskImageFolder)){
                print_r("yes");
                rmdir($taskImageFolder);
            }

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Task deleted");
            $response->send();
            exit;
        }
        catch(ImageException $ex){
            if($writeDB->intransaction()){
                $writeDB->rollback();
            }
            $response = new Response();   
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(PDOException $ex){
            if($writeDB->intransaction()){
                $writeDB->rollback();
            }
            $response = new Response();   
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("failed to delete task");
            $response->send();
            exit;
        }
    }
    elseif($_SERVER['REQUEST_METHOD'] === 'PATCH'){
        try{
            if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("content type header is not set JSON");
                $response->send();
                exit;
            } 
            
            $rawPatchData =  file_get_contents('php://input');
            if(!$jsonData = json_decode($rawPatchData)){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("request body  is not valid JSON");
                $response->send();
                exit;
            }

            $title_updated = false;
            $description_updated = false;
            $deadline_updated = false;
            $completed_updated = false;

            $queriyFields = "";
            if(isset($jsonData->title)){
                $title_updated = true;
                $queriyFields .= "title = :title, ";
            } 

            if(isset($jsonData->description)){
                $description_updated = true;
                $queriyFields .= "description = :description, ";
            }

            if(isset($jsonData->deadline)){
                $deadline_updated  = true;
                $queriyFields .= "deadline = STR_TO_DATE(:deadline, '%d/%m/%Y %H:%i'),";
            }

            if(isset($jsonData->completed)){
                $completed_updated = true;
                $queriyFields .= "completed = :completed,";
            }

            $queriyFields = rtrim($queriyFields, "," );
            
            if($title_updated === false && $description_updated === false && $deadline_updated && $completed_updated === false){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("No task fields provided");
                $response->send();
                exit;
            }
            
            $query = $writeDB->prepare("select id,title, description ,DATE_FORMAT(deadline,'%d/%m/%Y %H:%i') as deadline,completed from tbltasks where id = :taskid and userid = :userid");
            $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
            $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
            $query->execute();
            

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("No task found to update");
                $response->send();
                exit;
            }

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $task = new Task($row['id'],$row['title'],$row['description'],$row['deadline'],$row['completed']);
            }
            $queryString = "UPDATE tbltasks SET {$queriyFields} WHERE id = :taskid and userid = :userid";
            $query = $writeDB->prepare($queryString);
           
            if($title_updated == true){
                $task->setTitle($jsonData->title);
                $up_title = $task->getTitle();
                $query->bindParam(':title', $up_title,PDO::PARAM_STR);
            }
            if($description_updated == true){
                $task->setDescription($jsonData->description);
                $up_description = $task->getDescription();
                $query->bindParam(':description', $up_description,PDO::PARAM_STR);
            }
            
            if($deadline_updated == true){
                $task->setdeadline($jsonData->deadline);
                $up_deadline = $task->getDeadline();
                $query->bindParam(':deadline', $up_deadline,PDO::PARAM_STR);
            }
            if($completed_updated == true){
                $task->setCompleted($jsonData->completed);
                $up_completed = $task->getCompleted();
                $query->bindParam(':completed', $up_completed,PDO::PARAM_STR);
            }

            $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
            $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
            
            $query->execute();
            
            $rowCount = $query->rowCount();
            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage("Task not updated");
                $response->send();
                exit; 

            }
            
            $query = $writeDB->prepare("select id,title, description ,DATE_FORMAT(deadline,'%d/%m/%Y %H:%i') as deadline,completed from tbltasks where id = :taskid and userid = :userid");
            $query->bindParam(':taskid',$taskid,PDO::PARAM_INT);
            $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                $response->addMessage(" No task found afer update");
                $response->send();
                exit;   
            }

            $taskArray = array();
            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $imageArray = retrieveTaskImages($writeDB,$taskid,$returned_userid);
                $task = new Task($row['id'],$row['title'],$row['description'],$row['deadline'],$row['completed'],$imageArray);
               
                $taskArray[] = $task->returnTaskASArray();

            }
            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->addMessage("Task updated");
            $response->setData($returnData);
            $response->send();
            exit;
        }
        catch(ImageException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(TaskException $ex){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(PDOException $ex){
            error_log("Database query error -".$ex,0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("failed to update task check data for errors");
            $response->send();
            exit;   

        }
    }
    else{
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("METHOD not allowed");
        $response->send();
        exit;
    }
    
}elseif(array_key_exists("complete",$_GET)){
    $completed = $_GET['complete'];
    if($completed !== 'Y' && $completed !== 'N'){
        $response = new Response();
        $response->setHttpStatusCode(400);
        $response->setSuccess(false);
        $response->addMessage("Completed filter must be Y or N");
        $response->send();
        exit;
    }
    if($_SERVER['REQUEST_METHOD'] === 'GET'){
       
        try{
            $query = $readDB->prepare('SELECT id,title,description,DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") as deadline,completed from tbltasks where completed = :completed and userid = :userid ');
            $query->bindParam(':completed',$completed,PDO::PARAM_STR);
            $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
            $query->execute();
            $rowCount = $query->rowCount();

            $taskArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $imageArray = retrieveTaskImages($readDB,$row['id'],$returned_userid);
                $task = new Task($row['id'],$row['title'],$row['description'],$row['deadline'],$row['completed'],$imageArray);
                $taskArray[] = $task->returnTaskASArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit; 
        }
        catch(ImageException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(TaskException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }

        catch(PDOException $ex){
            error_log("Database query error -".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("failed to get task");
            $response->send();
            exit;
        }
    }
    else{
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit;
    }
}elseif(array_key_exists("page",$_GET)){
    if($_SERVER['REQUEST_METHOD'] === 'GET'){ 
        $page = $_GET['page'];
        if($page == ''|| !is_numeric($page)){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage("page number cannot be blank or must be numeric");
            $response->send();
            exit; 
        }
        $limitPerPage = 20;
        try{
            $query = $readDB->prepare('select count(id) as totalNoOftasks from tbltasks where userid = :userid');
            $query->bindParam(':userid',$returned_userid,PDO::PARAM_INT);
            $query->execute();

            $row = $query->fetch(PDO::FETCH_ASSOC);
            $tasksCount = intval($row['totalNoOftasks']);

            $numOfPages = ceil($tasksCount/$limitPerPage);

            if($numOfPages == 0){
                $numOfPages = 1;
            }
            if($page > $numOfPages){
                $response = new Response();
                $response->setHttpStatusCode(404);
                $response->setSuccess(false);
                $response->addMessage("page not found");
                $response->send();
                exit;
            }

            $offset = ($page == 1 ? 0 :($limitPerPage*($page-1)));
            $query = $readDB->prepare('select id,title, description ,DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") as deadline,completed from tbltasks where userid = :userid limit :pglimit offset :offset');
            $query->bindParam(':pglimit', $limitPerPage,PDO::PARAM_INT);
            $query->bindParam(':offset', $offset,PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid,PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $imageArray = retrieveTaskImages($readDB,$row['id'],$returned_userid);
                $task = new Task($row['id'],$row['title'],$row['description'],$row['deadline'],$row['completed'],$imageArray);
                $taskArray[] = $task->returnTaskASArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['total_rows'] = $tasksCount;
            $returnData['total_pages'] = $numOfPages;
            $returnData['has_next_page'] = $numOfPages;
            $returnData['has_previous_page'] = $numOfPages;
            ($page < $numOfPages ? $returnData['has_next_page'] == true : $returnData['has_next_page'] == false);
            ($page > 1 ? $returnData['has_previous_page'] == true : $returnData['has_previous_page'] == false);
            $returnData['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit;
        }
        catch(ImageException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(TaskException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(PDOException $ex){
            error_log("Database query error -".$ex,0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("failed to get task");
            $response->send();
            exit;
        }
    }else{
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false);
        $response->addMessage("Request method not allowed");
        $response->send();
        exit;
    }
}

elseif(empty($_GET)){
    if($_SERVER['REQUEST_METHOD'] === 'GET'){
        try{
            $query  =  $readDB->prepare('select id,title,description,DATE_FORMAT(deadline,"%d/%m/%Y %H:%i") as deadline,completed from tbltasks where userid = :userid');
            $query->bindParam(':userid', $returned_userid,PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();

            $taskArray = array();

            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $imageArray = retrieveTaskImages($readDB,$row['id'],$returned_userid);
                $task = new Task($row['id'],$row['title'],$row['description'],$row['deadline'],$row['completed'],$imageArray);
                $taskArray[] = $task->returnTaskASArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(200);
            $response->setSuccess(true);
            $response->toCache(true);
            $response->setData($returnData);
            $response->send();
            exit;
        }
        catch(ImageException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(TaskException $ex){
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(PDOException $ex){
            error_log("Database query error -".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("failed to get tasks");
            $response->send();
            exit;
        }
    }elseif($_SERVER['REQUEST_METHOD'] === 'POST'){
        try{
            if($_SERVER['CONTENT_TYPE'] !== 'application/json'){
                $response  = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false); 
                $response->addMessage("content type header is not set to json");
                $response->send();
                exit;
            }

            $rawPOSTData = file_get_contents('php://input');

            if(!$jsonData = json_decode($rawPOSTData)){
                $response  = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false); 
                $response->addMessage("Request body is not valid JSON ");
                $response->send();
                exit;
            }
            if(!isset($jsonData->title)|| !isset($jsonData->completed)){
                $response = new Response();
                $response->setHttpStatusCode(400);
                $response->setSuccess(false);
                (!isset($jsonData->title) ? $response->addMessage("title field's mandatory and must be provided"): false);
                (!isset($jsonData->title) ? $response->addMessage("completed field s mandatory and must be provided"): false);
                $response->send();
                exit;

            }

            $newTask = new Task(null,$jsonData->title,(isset($jsonData->description) ? $jsonData->description : null),(isset($jsonData->deadline) ? $jsonData->deadline : null),$jsonData->completed);

            $title = $newTask->gettitle();
            $description = $newTask->getdescription();
            $deadline = $newTask->getdeadline();
            $completed = $newTask->getcompleted();

            $query = $writeDB->prepare('insert into tbltasks(title,description,deadline,completed,userid) values(:title,:description, STR_TO_DATE(:deadline,\'%d/%m/%Y %H:%i\'),:completed ,:userid)');
            $query->bindParam(':title',$title,PDO::PARAM_INT);
            $query->bindParam(':description',$description,PDO::PARAM_STR);
            $query->bindParam(':deadline',$deadline,PDO::PARAM_STR);
            $query->bindParam(':completed',$completed,PDO::PARAM_STR);
            $query->bindParam(':userid', $returned_userid,PDO::PARAM_INT);
            $query->execute();

            $rowCount = $query->rowCount();
            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("failed to create task");
                $response->send();
                exit;
            }

            $lasttaskID = $writeDB->lastInsertId();
            
            $query = $writeDB->prepare('select id,title,description,DATE_FORMAT(deadline,"%d/%m/%Y %H:%i")as deadline,completed from tbltasks where id = :taskid and userid= :userid');
            $query->bindParam('taskid', $lasttaskID,PDO::PARAM_INT);
            $query->bindParam(':userid', $returned_userid,PDO::PARAM_INT);
            $query->execute();
            
            $rowCount = $query->rowCount();
            if($rowCount === 0){
                $response = new Response();
                $response->setHttpStatusCode(500);
                $response->setSuccess(false);
                $response->addMessage("failed to retrieve task");
                $response->send();
                exit; 
            }
            $taskArray =array();
            while($row = $query->fetch(PDO::FETCH_ASSOC)){
                $task = new Task($row['id'],$row['title'],$row['description'],$row['deadline'],$row['completed']);

                $taskArray[] = $task->returnTaskASArray();
            }

            $returnData = array();
            $returnData['rows_returned'] = $rowCount;
            $returnData['tasks'] = $taskArray;

            $response = new Response();
            $response->setHttpStatusCode(201);
            $response->setSuccess(true);
            $response->addMessage("Task created");
            $response->setData($returnData);
            $response->send();
            exit;

        }
        catch(TaskException $ex){
            $response = new Response();
            $response->setHttpStatusCode(400);
            $response->setSuccess(false);
            $response->addMessage($ex->getMessage());
            $response->send();
            exit;
        }
        catch(PDOException $ex){
            error_log("Database query error -".$ex, 0);
            $response = new Response();
            $response->setHttpStatusCode(500);
            $response->setSuccess(false);
            $response->addMessage("failed to insert task into database - check submitted data for errors");
            $response->send();
            exit;
        }  
    }else{
        $response = new Response();
        $response->setHttpStatusCode(405);
        $response->setSuccess(false); 
        $response->addMessage("Request Method not allowed");
        $response->send();
        exit;
    }
}
else{
    $response = new Response();
    $response->setHttpStatusCode(404);
    $response->setSuccess(false);
    $response->addMessage("Endpoint not found");
    $response->send();
    exit;
}
?>