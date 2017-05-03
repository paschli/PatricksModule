<?
//Modul schaltet einen Ausgang (LCN-Ausgang, LCN-Lämpchen, LCN-Relais, entfernte Variable (JSON Zugriff) 


class AutSw extends IPSModule {
  
  
    
    
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('Auswahl', 0); //Auswahl des Typs
    $this->RegisterPropertyInteger('idLCNInstance', 0); //ID der zu schaltenden Instanz
    $this->RegisterPropertyInteger('LaempchenNr', 0); //Falls es Lämpchen sind
    $this->RegisterPropertyInteger('Rampe', 2); // Rampe für das Schalten eines LCN Ausgangs
    $this->RegisterPropertyString('IPAddress', ''); //IP Adesse für remote schalten eines anderen IP-Symcon
    $this->RegisterPropertyString('Password', '');// Passwort für JSON-Verbindung
    $this->RegisterPropertyInteger('ZielID', '');// ID des zu schaltenden entfernten Objekts
    $this->RegisterPropertyString('Name','');//Otionaler Name für die erstellte Instanz
    $this->RegisterPropertyInteger('State', 0); //Status der Instanz
    $this->RegisterPropertyBoolean('AutoOff', FALSE);
    $this->RegisterPropertyBoolean('Timer', FALSE);
 //   $this->RegisterPropertyInteger('AutoOffCatID', 0); //Status der Instanz
    
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
    $statusID = $this->RegisterVariableBoolean('Status','Status','~Switch');//
    $this->RegisterPropertyBoolean('Status', FALSE);
    $this->RegisterPropertyInteger('Auswahl', 0); //Id der zu beobachtenden Variable
    $this->RegisterPropertyInteger('idLCNInstance', 0);
    $this->RegisterPropertyInteger('LaempchenNr', 0);
    $this->RegisterPropertyInteger('Rampe', 2);
    $this->RegisterPropertyString('IPAddress', '');
    $this->RegisterPropertyString('Password', '');
    $this->RegisterPropertyInteger('ZielID', 0);
    $this->RegisterPropertyString('Name','');
 //   $this->RegisterPropertyInteger('AutoOffCatID', 0); //Status der Instanz
    $this->RegisterPropertyInteger('State', 0); //Status der Instanz
    IPS_SetIcon($this->GetIDForIdent('Status'), 'Light');
    $instID= IPS_GetParent($statusID);
    
    if($this->ReadPropertyString('Name')!='')
        IPS_SetName($instID, $this->ReadPropertyString('Name'));
    
    
    $CatID = @IPS_GetCategoryIDByName('Konfig', $instID);
    if(!$CatID){    
//Kategorie erstellen        
        $CatID = IPS_CreateCategory();       // Kategorie anlegen
        $this->RegisterPropertyInteger('CatID_AutoOff',$CatID);//ID merken
        IPS_SetName($CatID, "Konfig"); // Kategorie benennen
        IPS_SetParent($CatID,$instID ); // Kategorie einsortieren unter dem Objekt 
        IPS_SetIcon($CatID, 'Gear');
//Laufzeit Auswahl erstellen
        $VarID= IPS_CreateVariable(1);
        IPS_SetName($VarID, "Set Laufzeit"); // Variable benennen
        IPS_SetPosition($VarID, 10);
        IPS_SetIcon($VarID, 'Hourglass');
        IPS_SetParent($VarID,$CatID );
        IPS_SetIdent($VarID,'SetLaufzeit');
        $SkriptID=IPS_CreateScript(0);
        IPS_SetName($SkriptID,'control');
        //IPS_SetPosition($SkriptID, 5);
        IPS_SetParent($SkriptID,$VarID);
        IPS_SetHidden($SkriptID, True);
        IPS_SetScriptContent($SkriptID, '<?SetValue($_IPS["VARIABLE"],$_IPS["VALUE"]); ?>');
        IPS_SetVariableCustomAction($VarID, $SkriptID);
        IPS_SetVariableCustomProfile($VarID, 'Time_4h');
//Laufzeit Anzeige erstellen
        $VarID= IPS_CreateVariable(1);
        IPS_SetName($VarID, "Laufzeit"); // Variable benennen
        IPS_SetPosition($VarID, 10);
        IPS_SetIcon($VarID, 'Hourglass');
        IPS_SetParent($VarID,$instID );
        IPS_SetHidden($VarID, True);
//Wahlschalter erstellen
        $ID=$this->RegisterVariableBoolean('AutoOff','Auto Off','~Switch');//
        $this->RegisterPropertyBoolean('AutoOff', FALSE);
        IPS_SetPosition($ID, 10);
        $ID=$this->RegisterVariableBoolean('Timer','Timer','~Switch');//
        $this->RegisterPropertyBoolean('Timer', FALSE);
        IPS_SetPosition($ID, 30);
        $autoffID= $this->GetIDForIdent('AutoOff');
        $timerID= $this->GetIDForIdent('Timer');
//Aktionen        
        $this->EnableAction("Timer");
        $this->EnableAction("AutoOff");
        IPS_SetParent($autoffID,$CatID );
        IPS_SetParent($timerID,$CatID );
        $this->RegisterTimer('AutoOffTimer', 60, "\$id = \$_IPS['TARGET'];\n".'AutSw_AutoOff($id);');
        $TimerID=$this->GetIDForIdent('AutoOffTimer');
        IPS_SetEventActive($TimerID, false);

    }
//Aktion, falls zu schaltendes Objekt von anderen Instanzen oder Schaltern geschaltet wird
    $typ= $this->ReadPropertyInteger('Auswahl');
    switch($typ){
            case 0: //falls Instanz nicht gewählt wurde
                break;
            case 1: //falls Instanz LCN Ausgang
                $ZielID= $this->ReadPropertyInteger('idLCNInstance');
                $ID_Relais_Children=IPS_GetChildrenIds($ZielID);
                for($i=0;$i<=count($ID_Relais_Children)-1;$i++){
                    if(IPS_GetName($ID_Relais_Children[$i])=="Status"){
                        $test_variable=$ID_Relais_Children[$i];
                        $variable_value= GetValueBoolean($test_variable);
                        IPS_LogMessage("AutoSwitch_ApplyChanges","Variable = ".$ID_Relais_Children[$i]." Typ = ".$test_variable['VariableType']);
                        $this->RegisterEvent('WatchEvent', $test_variable, "\$id = IPS_GetParent(\$_IPS['SELF']);\n".'AutSw_EventTrigger($id,"Status", GetValueBoolean(IPS_GetEvent($_IPS["SELF"])["TriggerVariableID"]));');
                    }
                
                }
                break;
            case 2: //falls Instanz LCN Relais
                break;
            case 3: //falls Instanz LCN Lämpchen
                break;
            case 4: //falls Instanz Fernzugriff
                break;
            case 5: //falls Instanz Switch-Modul
                if(@!IPS_GetObjectIDByIdent('WatchEvent', $this->InstanceID)){
                    $ZielID= $this->ReadPropertyInteger('idLCNInstance');
                    $ID_Relais_Children=IPS_GetChildrenIds($ZielID);
                    for($i=0;$i<=count($ID_Relais_Children)-1;$i++){
                        if(IPS_GetName($ID_Relais_Children[$i])=="Status"){
                            $test_variable=$ID_Relais_Children[$i];
                            $variable_value= GetValueBoolean($test_variable);
                            IPS_LogMessage("AutoSwitch_ApplyChanges","Variable = ".$ID_Relais_Children[$i]." Typ = ".$test_variable['VariableType']);
                            $this->RegisterEvent('WatchEvent', $test_variable, "\$id = IPS_GetParent(\$_IPS['SELF']);\n".'AutSw_EventTrigger($id,"Status", GetValueBoolean(IPS_GetEvent($_IPS["SELF"])["TriggerVariableID"]));');
                        }
                    }
                }
                break;
            default:
                break;
        }
    $this->EnableAction("Status");
//    $this->EnableAction("Timer");
//    $this->EnableAction("AutoOff");
    $this->GetConfigurationForm();
    
  }
  
 public function GetConfigurationForm() {
     
     $status_entry='{ "code": 101, "icon": "inactive", "caption": "Instanz wird erstellt" },
             { "code": 102, "icon": "active", "caption": "Instanz aktiv" },
             { "code": 200, "icon": "error", "caption": "Instanz fehlerhaft" }'; 
     
     
     $elements_entry0='{ "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 },
            { "label": "Schalter", "value": 5 }
          ]
        }';
     $elements_entry1='{ "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 },
            { "label": "Schalter", "value": 5 }
          ]},
          { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
          { "type": "NumberSpinner", "name": "Rampe", "caption": "Sekunden" },
          { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     $elements_entry2='{ "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 },
            { "label": "Schalter", "value": 5 }
          ]},
          { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
          { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
          
     
     $elements_entry3='{ "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 },
            { "label": "Schalter", "value": 5 }
          ]},
          { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
          { "name": "LaempchenNr", "type": "Select", "caption": "Lämpchen Nr.", 
        "options":[
            { "label": "Lämpchen 1", "value": 1 },
            { "label": "Lämpchen 2", "value": 2 },
            { "label": "Lämpchen 3", "value": 3 },
            { "label": "Lämpchen 4", "value": 4 },
            { "label": "Lämpchen 5", "value": 5 },
            { "label": "Lämpchen 6", "value": 6 },
            { "label": "Lämpchen 7", "value": 7 },
            { "label": "Lämpchen 8", "value": 8 },
            { "label": "Lämpchen 9", "value": 9 },
            { "label": "Lämpchen 10", "value": 10 },
            { "label": "Lämpchen 11", "value": 11 },
            { "label": "Lämpchen 12", "value": 12 }
          ]
        },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
     $elements_entry4='{ "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 },
            { "label": "Schalter", "value": 5 }
          ]},
          { "type": "ValidationTextBox", "name": "IPAddress", "caption": "Host"},
          { "type": "PasswordTextBox", "name": "Password", "caption": "Passwort" },
          { "type": "NumberSpinner", "name": "ZielID", "caption": "Ziel ID"},
          { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
     $action_entry='';
     $action_entry1='{ "type": "Label", "label": "Bitte die zu steuernde Instanz wählen" },
          { "type": "Button", "label": "An", "onClick": "AutSw_SetOn($id);" },
          { "type": "Button", "label": "Aus", "onClick": "AutSw_SetOff($id);" }';
     
     
     
     $wahl=$this->ReadPropertyInteger('Auswahl');
     switch($wahl){
         case 0:  $elements_entry=$elements_entry0; break;
         case 1:  $elements_entry=$elements_entry1; break;
         case 2:  $elements_entry=$elements_entry2; break;
         case 3:  $elements_entry=$elements_entry3; break;
         case 4:  $elements_entry=$elements_entry4; break;
         case 5:  $elements_entry=$elements_entry2; break;
     }
     
     if($this->ReadPropertyInteger('idLCNInstance')>0)
         $action_entry=$action_entry1;  
     if(($this->ReadPropertyString('IPAddress')!='')&&($this->ReadPropertyString('Password')!='')&&
            ($this->ReadPropertyInteger('ZielID')!=0))
         $action_entry=$action_entry1; 
     
     $form='{ "status":['.$status_entry.'],"elements":['.$elements_entry.'],"actions":['.$action_entry.'],}';
     return $form;
      
}   
public function EventTrigger($ident, $value) {
    IPS_LogMessage("AutoSwitch_EventTrigger","Ident: ".$ident." Value: ".$value);
 //   $this->RequestAction('Status', $value);
    $par= IPS_GetParent(($this->GetIDForIdent('Status')));
    $CatID =IPS_GetCategoryIDByName('Konfig', $par);
    $LaufzeitID= IPS_GetVariableIDByName('Set Laufzeit', $CatID);
    $Laufzeit= GetValueInteger($LaufzeitID);
    $AutoOffID=IPS_GetObjectIDByIdent('AutoOff', $CatID);
    $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
    if($value && GetValueBoolean($AutoOffID)){
        $TimerID=@$this->GetIDForIdent('AutoOffTimer');
        if($TimerID)
            IPS_SetEventActive($TimerID, TRUE);
            SetValueInteger($IDLaufz, $Laufzeit);
            IPS_SetHidden($IDLaufz, FALSE);
        }
    else {
        IPS_SetHidden($IDLaufz, TRUE);
    }
    $this->Set($value);
        
}
 public function RequestAction($ident, $value) {
     $par= IPS_GetParent(($this->GetIDForIdent('Status')));
//     $name=@IPS_GetName($this->GetIDForIdent($ident));
     $CatID =IPS_GetCategoryIDByName('Konfig', $par);
//     if(!$name){
//         $CatID =IPS_GetCategoryIDByName('Konfig', $par);
//         echo($CatID);
//         if($CatID){
//            $name=IPS_GetName(IPS_GetObjectIDByIdent($ident, $CatID)); 
//         }   
//     }
     
     if($ident=='AutoOff'){
        SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value);
        if($value){
            $LaufzeitID= IPS_GetVariableIDByName('Set Laufzeit', $CatID);
            $Laufzeit= GetValueInteger($LaufzeitID);
            $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
            SetValueInteger($IDLaufz, $Laufzeit);
        }
        else{
            $timerID= @IPS_GetObjectIDByIdent('AutoOffTimer', $par);
            if($timerID)
                IPS_SetEventActive($timerID, FALSE);
        }
     } 
     else if($ident=='Timer'){
        $this->Set($value);
     }
     else if($ident=='Status'){
        $LaufzeitID= IPS_GetVariableIDByName('Set Laufzeit', $CatID);
        $Laufzeit= GetValueInteger($LaufzeitID);
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff', $CatID);
        $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
        if($value && GetValueBoolean($AutoOffID)){
            $TimerID=@$this->GetIDForIdent('AutoOffTimer');
            if($TimerID)
                IPS_SetEventActive($TimerID, TRUE);
            SetValueInteger($IDLaufz, $Laufzeit);
            IPS_SetHidden($IDLaufz, FALSE);
        }
        else {
            IPS_SetHidden($IDLaufz, TRUE);
        }
        $this->Set($value);
        
     }
     
//Neuen Wert in die Statusvariable schreiben
      
}

public function SetOn() {
      $this->Set(True);
      }
public function SetOff() {
      $this->Set(False);
      }

public function checkVerb() {
      $password= $this->ReadPropertyString('Password'); 
      $IPAddr= $this->ReadPropertyString('IPAddress');
      $TargetID=(integer) $this->ReadPropertyInteger('ZielID');
      $mes="http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/";
      IPS_LogMessage("AutoSwitch_Check","Aufruf:".$mes."Target ID".$TargetID);
      $rpc = new JSONRPC("http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/");
      try {
          //$rpc->IPS_GetKernelDir();
          $rpc->GetValue($TargetID);
        } 
      catch (JSONRPCException $e) {
          echo 'RPC Problem: ',  $e->getMessage(), "\n";
        } 
      catch (Exception $e) {
          echo 'Server Problem: ',  $e->getMessage(), "\n";
        }
         $this->RegisterPropertyInteger('State',1);
}     
      
public function AutoOff() {
    $par= IPS_GetParent(($this->GetIDForIdent('Status')));
    $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
    $Lauzeit= GetValueInteger($IDLaufz);
    $Lauzeit--;
    if($Lauzeit)
        SetValueInteger ($IDLaufz, $Lauzeit);
    else{
        SetValueInteger ($IDLaufz, $Lauzeit);
        IPS_SetHidden($IDLaufz, TRUE);
        $timerID= IPS_GetObjectIDByIdent('AutoOffTimer', $par);
        IPS_SetEventActive($timerID, FALSE);
        $this->Set(FALSE);
    }              
}

protected function RegisterTimer($ident, $interval, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    
    if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
      IPS_DeleteEvent($id);
      $id = 0;
    }
    if (!$id) {
      $id = IPS_CreateEvent(1);
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
      IPS_LogMessage("AutoSwitch_RegisterTimer","Timer ".$id." erstellt");
    }
    
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, $script);
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
    if (!($interval > 0)) {
        IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, 1);
        IPS_SetEventActive($id, false);
    } 
    else {
        IPS_SetEventCyclic($id, 0, 0, 0, 0, 1, $interval);
        IPS_SetEventActive($id, true);
    }
  }

  protected function RegisterEvent($ident,$ZielID, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    if ($id && IPS_GetEvent($id)['EventType'] <> 0) {
      IPS_DeleteEvent($id);
      $id = 0;
    }
    if (!$id) {
      $id = IPS_CreateEvent(0);
      IPS_SetEventTrigger($id, 0, $ZielID); //Bei Update von der gewählten Variable 
      IPS_SetEventActive($id, true);             //Ereignis aktivieren
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
      IPS_LogMessage("AutoSwitch_RegisterEvent","Event ".$id." erstellt");
    }
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, $script);
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
  }
  
  
  public function Set_Timer($Laufzeit) {
    $par= IPS_GetParent(($this->GetIDForIdent('Status')));
    $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
    $TimerID=@$this->GetIDForIdent('AutoOffTimer');
    if($TimerID)
        IPS_SetEventActive($TimerID, TRUE);
    SetValueInteger($IDLaufz, $Laufzeit);
    IPS_SetHidden($IDLaufz, FALSE);
    $this->Set(TRUE);
  }
  
public function Set(bool $value) {
    if(IPS_SemaphoreEnter('AutoSwitch_Set', 1000)) {
      $value_dim=0;
      $typ= $this->ReadPropertyInteger('Auswahl');
      
      switch($typ){
          case 0: break;
          case 1: $instID=$this->ReadPropertyInteger('idLCNInstance');
            $dim_time= $this->ReadPropertyInteger('Rampe');
            if($value){
                LCN_SetIntensity($instID, 100, $dim_time);
            }
            else {
                LCN_SetIntensity($instID, 0, $dim_time);
            }
            SetValue($this->GetIDForIdent("Status"), $value);
            break;
          case 2: $instID=$this->ReadPropertyInteger('idLCNInstance');
            LCN_SwitchRelay($instID, $value);
            SetValue($this->GetIDForIdent("Status"), $value);
            break;
          case 3: $lcn_instID=$this->ReadPropertyInteger('idLCNInstance');
            $lampNo=$this->ReadPropertyInteger('LaempchenNr');
            if($value){
              LCN_SetLamp($lcn_instID,$lampNo,'E');  
            }
            else{
              LCN_SetLamp($lcn_instID,$lampNo,'A');  
            }
            SetValue($this->GetIDForIdent("Status"), $value);
            break;
          case 4: 
            $password= $this->ReadPropertyString('Password'); 
            $IPAddr= $this->ReadPropertyString('IPAddress');
            $TargetID=(integer) $this->ReadPropertyInteger('ZielID');
            $mes="http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/";
            IPS_LogMessage("AutoSwitch_Set","Aufruf".$mes);
            IPS_LogMessage("AutoSwitch_Set","Target ID".$TargetID);
            $rpc = new JSONRPC("http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/");
            if($value){
                //IPS_LogMessage(Modul,"Value = True => Relais An");
                $rpc->SetValue($TargetID, true);
            }           
            else{
                //IPS_LogMessage(Modul,"Value = False => Relais Aus");
                $rpc->SetValue($TargetID, false);
            }
            $result=(bool)$rpc->GetValue($TargetID);
            SetValue($this->GetIDForIdent("Status"), $result);
            break;
          case 5: $lcn_instID=$this->ReadPropertyInteger('idLCNInstance');
            if($value){
                IPS_LogMessage("AutoSwitch_Set","Aufruf AN Schalter_Set ID=".$lcn_instID);
                Schalter_Set($lcn_instID,1);  
            }
            else{
                IPS_LogMessage("AutoSwitch_Set","Aufruf AUS Schalter_Set ID=".$lcn_instID);
                Schalter_Set($lcn_instID,0);  
            }
            SetValue($this->GetIDForIdent("Status"), $value);
            break;  
          default: break;
      }

       IPS_SemaphoreLeave('AutoSwitch_Set');
     } 
     else {
      IPS_LogMessage('AutoSwitch', 'Semaphore Timeout');
    }
   }
} 
?>
