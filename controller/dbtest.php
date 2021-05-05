<?php
require_once('db.php');
require_once('../model/Response.php');
try{
    $writeDb = DB::connectionWriteDB();
    $readDb  = DB::connectionReadDb();
}
catch(PDOException $ex) {
    $response = new Response();
    $response->setHttpStatusCode(500);
    $response->setSuccess(false);
    $response->addMessage("Database Connection error");
    $response->send();
    exit;
}
?>