<?php
/*
 * @version $Id$
 -------------------------------------------------------------------------
 GLPI - Gestionnaire Libre de Parc Informatique
 Copyright (C) 2003-2009 by the INDEPNET Development Team.

 http://indepnet.net/   http://glpi-project.org
 -------------------------------------------------------------------------

 LICENSE

 This file is part of GLPI.

 GLPI is free software; you can redistribute it and/or modify
 it under the terms of the GNU General Public License as published by
 the Free Software Foundation; either version 2 of the License, or
 (at your option) any later version.

 GLPI is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 GNU General Public License for more details.

 You should have received a copy of the GNU General Public License
 along with GLPI; if not, write to the Free Software
 Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
 --------------------------------------------------------------------------
 */

// ----------------------------------------------------------------------
// Original Author of file:
// Purpose of file:
// ----------------------------------------------------------------------

if (!defined('GLPI_ROOT')){
   die("Sorry. You can't access directly to this file");
}

/**
 * Disconnects a direct connection
 *
 *
 * @param $ID the connection ID to disconnect.
 * @param $dohistory make history
 * @param $doautoactions make auto actions on disconnect
 * @param $ocsservers_id ocs server id of the computer if know
 * @return nothing
 */
function Disconnect($ID,$dohistory=1,$doautoactions=true,$ocsservers_id=0) {
   global $DB,$LINK_ID_TABLE,$LANG,$CFG_GLPI;

   //Get info about the periph
   $query = "SELECT `items_id`,`computers_id`,`itemtype`
             FROM `glpi_computers_items`
             WHERE `id`='$ID'";
   $res = $DB->query($query);

   if($DB->numrows($res)>0) {
      // Init
      if ($dohistory || $doautoactions) {
         $data = $DB->fetch_array($res);
         $decoConf = "";
         $type_elem= $data["itemtype"];
         $id_elem= $data["items_id"];
         $id_parent= $data["computers_id"];
         $table = $LINK_ID_TABLE[$type_elem];

         //Get the computer name
         $computer = new Computer;
         $computer->getFromDB($id_parent);

         //Get device fields
         $device=new CommonItem();
         $device->getFromDB($type_elem,$id_elem);
      }
      if ($dohistory) {
         //History log
         //Log deconnection in the computer's history
         $changes[0]='0';
         if ($device->getField("serial")) {
            $changes[1]=addslashes($device->getField("name")." -- ".$device->getField("serial"));
         } else {
            $changes[1]=addslashes($device->getField("name"));
         }
         $changes[2]="";
         historyLog ($id_parent,COMPUTER_TYPE,$changes,$type_elem,HISTORY_DISCONNECT_DEVICE);

         //Log deconnection in the device's history
         $changes[1]=addslashes($computer->fields["name"]);
         historyLog ($id_elem,$type_elem,$changes,COMPUTER_TYPE,HISTORY_DISCONNECT_DEVICE);
      }
      if ($doautoactions) {
         if (!$device->getField('is_global')) {
            $updates=array();
            if ($CFG_GLPI["is_location_autoclean"] && $device->getField('locations_id')) {
               $updates[]="locations_id";
               $device->obj->fields['locations_id']=0;
            }
            if ($CFG_GLPI["is_user_autoclean"] && $device->getField('users_id')) {
               $updates[]="users_id";
               $device->obj->fields['users_id']=0;
            }
            if ($CFG_GLPI["is_group_autoclean"] && $device->getField('groups_id')) {
               $updates[]="groups_id";
               $device->obj->fields['groups_id']=0;
            }
            if ($CFG_GLPI["is_contact_autoclean"] && $device->getField('contact')) {
               $updates[]="contact";
               $device->obj->fields['contact']="";
            }
            if ($CFG_GLPI["is_contact_autoclean"] && $device->getField('contact_num')) {
               $updates[]="contact_num";
               $device->obj->fields['contact_num']="";
            }
            if ($CFG_GLPI["state_autoclean_mode"]<0 && $device->getField('states_id')) {
               $updates[]="states_id";
               $device->obj->fields['states_id']=0;
            }
            if ($CFG_GLPI["state_autoclean_mode"]>0
                && $device->getField('states_id') != $CFG_GLPI["state_autoclean_mode"]) {
               $updates[]="states_id";
               $device->obj->fields['states_id']=$CFG_GLPI["state_autoclean_mode"];
            }
            if (count($updates)) {
               $device->obj->updateInDB($updates);
            }
         }
         if ($ocsservers_id==0) {
            $ocsservers_id = getOCSServerByMachineID($data["computers_id"]);
         }
         if ($ocsservers_id>0) {
            //Get OCS configuration
            $ocs_config = getOcsConf($ocsservers_id);

            //Get the management mode for this device
            $mode = getMaterialManagementMode($ocs_config,$type_elem);
            $decoConf= $ocs_config["deconnection_behavior"];

            //Change status if :
            // 1 : the management mode IS NOT global
            // 2 : a deconnection's status have been defined
            // 3 : unique with serial
            if($mode >= 2 && strlen($decoConf)>0) {
               //Delete periph from glpi
               if($decoConf == "delete") {
                  $device->obj->delete($id_elem);
               //Put periph in trash
               } else if($decoConf == "trash") {
                  $tmp["id"]=$id_elem;
                  $tmp["is_deleted"]=1;
                  $device->obj->update($tmp,$dohistory);
               }
            }
         } // $ocsservers_id>0
      }
      // Disconnects a direct connection
      $connect = new Connection;
      $connect->deletefromDB($ID);
   }
}

/**
 * Makes a direct connection
 *
 * @param $sID connection source ID.
 * @param $computers_id computer ID (where the sID would be connected).
 * @param $itemtype connection type.
 * @param $dohistory store change in history ?
 */
function Connect($sID,$computers_id,$itemtype,$dohistory=1) {
   global $LANG,$CFG_GLPI,$DB;

   // Makes a direct connection
   // Mise a jour lieu du periph si nécessaire
   $dev=new CommonItem();
   $dev->getFromDB($itemtype,$sID);

   // Handle case where already used, should never happen (except from OCS sync)
   if (!$dev->getField('is_global') ) {
      $query = "SELECT `id`, `computers_id`
                FROM `glpi_computers_items`
                WHERE `glpi_computers_items`.`items_id` = '$sID'
                      AND `glpi_computers_items`.`itemtype` = '$itemtype'";
      $result = $DB->query($query);
      while ($data=$DB->fetch_assoc($result)) {
         Disconnect($data["id"],$dohistory);

         // As we come from OCS, do not lock the device
         switch ($itemtype) {
            case MONITOR_TYPE :
               deleteInOcsArray($data["computers_id"],$data["id"],"import_monitor");
               break;

            case DEVICE_TYPE:
               deleteInOcsArray($data["computers_id"],$data["id"],"import_device");
               break;

            case PERIPHERAL_TYPE:
               deleteInOcsArray($data["computers_id"],$data["id"],"import_peripheral");
               break;

            case PRINTER_TYPE:
               deleteInOcsArray($data["computers_id"],$data["id"],"import_printer");
               break;
         }
      }
   }
   // Create the New connexion
   $connect = new Connection;
   $connect->items_id=$sID;
   $connect->computers_id=$computers_id;
   $connect->itemtype=$itemtype;
   $newID=$connect->addtoDB();

   if ($dohistory) {
      $changes[0]='0';
      $changes[1]="";
      if ($dev->getField("serial")) {
         $changes[2]=addslashes($dev->getField("name")." -- ".$dev->getField("serial"));
      } else {
         $changes[2]=addslashes($dev->getField("name"));
      }
      //Log connection in the device's history
      historyLog ($computers_id,COMPUTER_TYPE,$changes,$itemtype,HISTORY_CONNECT_DEVICE);
   }

   if (!$dev->getField('is_global')) {
      $comp=new Computer();
      $comp->getFromDB($computers_id);

      if ($dohistory){
         $changes[2]=addslashes($comp->fields["name"]);
         historyLog ($sID,$itemtype,$changes,COMPUTER_TYPE,HISTORY_CONNECT_DEVICE);
      }
      if ($CFG_GLPI["is_location_autoupdate"]
          && $comp->fields['locations_id'] != $dev->getField('locations_id')){
         $updates[0]="locations_id";
         $dev->obj->fields['locations_id']=addslashes($comp->fields['locations_id']);
         $dev->obj->updateInDB($updates);
         addMessageAfterRedirect($LANG['computers'][48],true);
      }
      if (($CFG_GLPI["is_user_autoupdate"]
           && $comp->fields['users_id'] != $dev->getField('users_id'))
          || ($CFG_GLPI["is_group_autoupdate"]
              && $comp->fields['groups_id'] != $dev->getField('groups_id'))) {
         if ($CFG_GLPI["is_user_autoupdate"]) {
            $updates[]="users_id";
            $dev->obj->fields['users_id']=$comp->fields['users_id'];
         }
         if ($CFG_GLPI["is_group_autoupdate"]) {
            $updates[]="groups_id";
            $dev->obj->fields['groups_id']=$comp->fields['groups_id'];
         }
         $dev->obj->updateInDB($updates);
         addMessageAfterRedirect($LANG['computers'][50],true);
      }

      if ($CFG_GLPI["is_contact_autoupdate"]
          && ($comp->fields['contact'] != $dev->getField('contact')
              || $comp->fields['contact_num'] != $dev->getField('contact_num'))) {
         $updates[0]="contact";
         $updates[1]="contact_num";
         $dev->obj->fields['contact']=addslashes($comp->fields['contact']);
         $dev->obj->fields['contact_num']=addslashes($comp->fields['contact_num']);
         $dev->obj->updateInDB($updates);
         addMessageAfterRedirect($LANG['computers'][49],true);
      }
      if ($CFG_GLPI["state_autoupdate_mode"]<0
          && $comp->fields['states_id'] != $dev->getField('states_id')) {
         $updates[0]="states_id";
         $dev->obj->fields['states_id']=$comp->fields['states_id'];
         $dev->obj->updateInDB($updates);
         addMessageAfterRedirect($LANG['computers'][56],true);
      }
      if ($CFG_GLPI["state_autoupdate_mode"]>0
          && $dev->getField('states_id') != $CFG_GLPI["state_autoupdate_mode"]) {
         $updates[0]="states_id";
         $dev->obj->fields['states_id']=$CFG_GLPI["state_autoupdate_mode"];
         $dev->obj->updateInDB($updates);
      }
   }
   return $newID;
}

/**
 * Get the connection count of an item
 *
 * @param $itemtype item type
 * @param $ID item ID
 * @return integer connection count
 */
function getNumberConnections($itemtype,$ID) {
   global $DB;

   $query = "SELECT count(*)
             FROM `glpi_computers_items`
             INNER JOIN `glpi_computers`
                        ON (`glpi_computers_items`.`computers_id`=`glpi_computers`.`id`)
             WHERE `glpi_computers_items`.`items_id` = '$ID'
                   AND `glpi_computers_items`.`itemtype` = '$itemtype'
                   AND `glpi_computers`.`is_deleted`='0'
                   AND `glpi_computers`.`is_template`='0'";

   $result = $DB->query($query);

   if ($DB->numrows($result)!=0) {
      return $DB->result($result,0,0);
   } else return 0;
}

/**
 * Unglobalize an item : duplicate item and connections
 *
 * @param $itemtype item type
 * @param $ID item ID
 */
function unglobalizeDevice($itemtype,$ID) {
   global $DB;

   $ci=new CommonItem();
   // Update item to unit management :
   $ci->getFromDB($itemtype,$ID);
   if ($ci->getField('is_global')) {
      $input=array("id"=>$ID,"is_global"=>"0");
      $ci->obj->update($input);

      // Get connect_wire for this connection
      $query = "SELECT `glpi_computers_items`.`id` AS connectID
                FROM `glpi_computers_items`
                WHERE `glpi_computers_items`.`items_id` = '$ID'
                      AND `glpi_computers_items`.`itemtype` = '$itemtype'";
      $result=$DB->query($query);
      if (($nb=$DB->numrows($result))>1) {
         for ($i=1;$i<$nb;$i++) {
            // Get ID of the computer
            if ($data=$DB->fetch_array($result)) {
               // Add new Item
               unset($ci->obj->fields['id']);
               if ($newID=$ci->obj->add(array("id"=>$ID))) {
                  // Update Connection
                  $query2="UPDATE `glpi_computers_items`
                           SET `items_id`='$newID'
                           WHERE `id`='".$data["connectID"]."'";
                  $DB->query($query2);
               }
            }
         }
      }
   }
}

?>