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
    $this->RegisterPropertyBoolean('AutoOff_Switch', FALSE);
    $this->RegisterPropertyBoolean('Timer_Switch', FALSE);
    $this->RegisterPropertyBoolean('WatchTarget',FALSE);
    $this->RegisterPropertyBoolean('SelAutoOff',FALSE);
    $this->RegisterPropertyBoolean('SelTimer',FALSE);
    $this->RegisterPropertyInteger('SliderAnz',0);
    
    $statusID = $this->RegisterVariableBoolean('Status','Status','~Switch');//
    $this->RegisterPropertyBoolean('Status', FALSE);
    IPS_SetIcon($this->GetIDForIdent('Status'), 'Light');
    
    
 //   $this->RegisterPropertyInteger('AutoOffCatID', 0); //Status der Instanz
    
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
    $this->EnableAction("Status");
    $instID= IPS_GetParent($this->GetIDforIdent('Status'));  
    if($this->ReadPropertyString('Name')!='')
        IPS_SetName($instID, $this->ReadPropertyString('Name'));
    $CatID = @IPS_GetCategoryIDByName('Konfig', $instID);
    if(!$CatID){    
//Kategorie erstellen 
    $CatID= $this->CreateCategorie($instID);    
//Auswahlvariable für Laufzeit erstellen
    $this->CreateAnzVar('SetLaufzeit','Set Laufzeit',$CatID,10,'Hourglass','<?SetValue($_IPS["VARIABLE"],$_IPS["VALUE"]); ?>','');
//Laufzeit Anzeige erstellen
    $this->CreateAnzVar('Laufzeit','Laufzeit',$instID,10,'Hourglass','','');
//Wahlschalter "AutoOff" erstellen
    $this->CreateWahlVar('AutoOff_Switch', 'Auto Off', '~Switch', $CatID);
//Wahlschalter "Timer" erstellen        
    $this->CreateWahlVar('Timer_Switch', 'Timer', '~Switch', $CatID);
    }
//Aktion, falls zu schaltendes Objekt von anderen Instanzen oder Schaltern geschaltet wird
    $scriptDevice="\$id = \$_IPS['TARGET'];\n".
                    'AutSw_EventTrigger($id,$id, GetValueBoolean(IPS_GetEvent($_IPS["EVENT"])["TriggerVariableID"]));';
    if($this->ReadPropertyInteger('idLCNInstance'))
        $typ= $this->ReadPropertyInteger('Auswahl');
    else 
        $typ=0;
    
    
    if($this->ReadPropertyBoolean('SelAutoOff')){
        $this->RegisterTimer('AutoOffTimer', 60, "\$id = \$_IPS['TARGET'];\n".'AutSw_AutoOff($id);');
        $TimerID=$this->GetIDForIdent('AutoOffTimer');
        IPS_SetEventActive($TimerID, false);
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
        IPS_SetHidden($AutoOffID, FALSE);
        
    }
    else{
        $TimerID=@$this->GetIDforIdent('AutoOffTimer');
        if($TimerID){
            IPS_SetEventActive($TimerID, False);   
        }
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
        IPS_SetHidden($AutoOffID, True);
    }
    
 /*   if(!$this->ReadPropertyBoolean('WatchTarget')||!$this->ReadPropertyBoolean('SelAutoOff')){
        $typ=0;
        $EventID=@IPS_GetObjectIDByIdent('WatchEvent', $this->InstanceID);
        if($EventID){
            IPS_SetEventActive($EventID,FALSE);
        }
    }    
 */       
    
    switch($typ){
            case 0: //falls Instanz nicht gewählt wurde
                break;
            case 1: //falls Instanz LCN Ausgang
                $this->CheckEvent($scriptDevice);//prüft ob Event vorhanden ist und setzt die Überwachung auf den Staus der Instanz
                if(!@IPS_GetObjectIDByIdent('SliderAnz', $instID)){
                    $script='SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);\n'.
                            'LCN_SetIntensity($this->ReadPropertyInteger("idLCNInstance"),$_IPS["VALUE"],0);';
                    $SliderID=$this->CreateAnzVar('SliderAnz', 'Slider', $instID, 20, 'Intensity',$script,'~Intensity.100' );
                    IPS_SetHidden($SliderID, FALSE);
                }
                break;
            case 2: //falls Instanz LCN Relais
                $this->CheckEvent($scriptDevice);//prüft ob Event vorhanden ist und setzt die Überwachung auf den Staus der Instanz
                break;
            case 3: //falls Instanz LCN Lämpchen
                break;
            case 4: //falls Instanz Fernzugriff
                break;
            case 5: //falls Instanz Switch-Modul
                $this->CheckEvent($scriptDevice);//prüft ob Event vorhanden ist und setzt die Überwachung auf den Staus der Instanz
                break;
            default:
                break;
        }
    $this->GetConfigurationForm(); 
  }
  
  
  
 public function GetConfigurationForm() {
     
    $status_entry='{ "code": 101, "icon": "inactive", "caption": "Instanz wird erstellt" },
             { "code": 102, "icon": "active", "caption": "Instanz aktiv" },
             { "code": 200, "icon": "error", "caption": "Instanz fehlerhaft" }'; 
     
     
    $elements_entry_device='
        { "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 },
            { "label": "Schalter", "value": 5 }
          ]
        }';
    $elements_entry_lcnOutput=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
        { "type": "NumberSpinner", "name": "Rampe", "caption": "Sekunden" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
    $elements_entry_lcnRelais=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
          
     
    $elements_entry_lcnLämpchen=',
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
     
    $elements_entry_jsonZugriff=',
        { "type": "ValidationTextBox", "name": "IPAddress", "caption": "Host"},
        { "type": "PasswordTextBox", "name": "Password", "caption": "Passwort" },
        { "type": "NumberSpinner", "name": "ZielID", "caption": "Ziel ID"},
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
    $elements_entry_AutoOff=',{ "type": "CheckBox", "name": "SelAutoOff", "caption": "Countdown-Timer-Funktion hinzufügen" }';
    
    $elements_entry_Timer=',{ "type": "CheckBox", "name": "SelTimer", "caption": "Timer Funktion" }';
    
    $elements_entry_AutoOffWatch=',{ "type": "CheckBox", "name": "WatchTarget", "caption": "Ziel überwachen" }';        
            
    $action_entry='';
    $action_entry1='{ "type": "Label", "label": "Bitte die zu steuernde Instanz wählen" },
          { "type": "Button", "label": "An", "onClick": "AutSw_SetOn($id);" },
          { "type": "Button", "label": "Aus", "onClick": "AutSw_SetOff($id);" }';
     
     
     
    $wahl=$this->ReadPropertyInteger('Auswahl');
    switch($wahl){
        case 0:  $elements_entry=$elements_entry_device; break;
        case 1:  $elements_entry=$elements_entry_device.$elements_entry_lcnOutput; break;
        case 2:  $elements_entry=$elements_entry_device.$elements_entry_lcnRelais; break;
        case 3:  $elements_entry=$elements_entry_device.$elements_entry_lcnLämpchen; break;
        case 4:  $elements_entry=$elements_entry_device.$elements_entry_jsonZugriff; break;
        case 5:  $elements_entry=$elements_entry_device.$elements_entry_lcnRelais; break;
    }
     
    if($this->ReadPropertyBoolean('SelAutoOff')&&($wahl!=3)){
        $elements_entry_AutoOff=$elements_entry_AutoOff.$elements_entry_AutoOffWatch; 
    } 
    else{
        $elements_entry_AutoOff=$elements_entry_AutoOff;
    }
        
    if($this->ReadPropertyInteger('idLCNInstance')>0){
        $action_entry=$action_entry1;
        $elements_entry=$elements_entry.$elements_entry_AutoOff.$elements_entry_Timer;
    }
    
    if(($this->ReadPropertyString('IPAddress')!='')&&($this->ReadPropertyString('Password')!='')&&
    ($this->ReadPropertyInteger('ZielID')!=0))
        $action_entry=$action_entry1; 
     
    $form='{ "status":['.$status_entry.'],"elements":['.$elements_entry.'],"actions":['.$action_entry.'],}';
    return $form;
      
} 

public function EventTrigger(int $par,bool $value) {
    IPS_LogMessage("AutoSwitch_EventTrigger","Ident: ".$par." Value: ".$value);
    $CatID =IPS_GetCategoryIDByName('Konfig', $par);
    $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
    $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
    if($value && GetValueBoolean($AutoOffID) && $this->ReadPropertyBoolean('SelAutoOff')){
        $LaufzeitID= IPS_GetVariableIDByName('Set Laufzeit', $CatID);
        $Laufzeit= GetValueInteger($LaufzeitID);
        $TimerID=@$this->GetIDForIdent('AutoOffTimer');
        if($TimerID)
            IPS_SetEventActive($TimerID, TRUE);
        SetValueInteger($IDLaufz, $Laufzeit);
        IPS_SetHidden($IDLaufz, FALSE);
    }
    else{
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
     
     if($ident=='AutoOff_Switch'){
        SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value);
        if($value){
            $LaufzeitID= IPS_GetVariableIDByName('Set Laufzeit', $CatID);
            $Laufzeit= GetValueInteger($LaufzeitID);
            $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
            SetValueInteger($IDLaufz, $Laufzeit);
            $eventID= @IPS_GetObjectIDByIdent('WatchTarget', $par);
            if($eventID)
                IPS_SetEventActive($eventID, True);
        }
        else{
            $timerID= @IPS_GetObjectIDByIdent('AutoOffTimer', $par);
            if($timerID)
                IPS_SetEventActive($timerID, FALSE);
/*            $eventID= @IPS_GetObjectIDByIdent('WatchTarget', $par);
            if($eventID)
                IPS_SetEventActive($eventID, FALSE);*/
        }
     } 
     else if($ident=='Timer_Switch'){
        $this->Set($value);
     }
     else if($ident=='Status'){
        $LaufzeitID= IPS_GetVariableIDByName('Set Laufzeit', $CatID);
        $Laufzeit= GetValueInteger($LaufzeitID);
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
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
     else if($ident=='SliderAnz'){
         IPS_LogMessage("AutoSwitch_RequestAction","Ident: ".$ident." Value: ".$value);
        SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value); 
        $instID=$this->ReadPropertyInteger('idLCNInstance');
        LCN_SetIntensity($instID, $value, 0);
     }
     
//Neuen Wert in die Statusvariable schreiben
      
}

public function SetOn() {
      $this->Set(True);
      }
public function SetOff() {
      $this->Set(False);
      }

private function checkVerb() {
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
        SetValueInteger($this->ReadPropertyInteger('State'),1);
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
      IPS_SetEventTrigger($id, 1, $ZielID); //Bei Update von der gewählten Variable 
      IPS_SetEventActive($id, true);             //Ereignis aktivieren
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
      IPS_LogMessage("AutoSwitch_RegisterEvent","Event ".$id." erstellt");
    }
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, $script);
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
    return($id);
  }
  
  
  public function Set_Timer(int $Laufzeit) {
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

private function CreateCategorie($instID) {
    $CatID = IPS_CreateCategory();       // Kategorie anlegen
    IPS_SetName($CatID, "Konfig"); // Kategorie benennen
    IPS_SetParent($CatID,$instID ); // Kategorie einsortieren unter dem Objekt 
    IPS_SetIcon($CatID, 'Gear'); //Icon setzen
    return($CatID);
   }
private function CreateAnzVar($ident,$name,$CatID,$pos,$icon,$script,$profil){
    $VarID= IPS_CreateVariable(1);
    IPS_SetName($VarID, $name); // Variable benennen
    IPS_SetPosition($VarID, $pos);
    IPS_SetIcon($VarID, $icon);
    IPS_SetParent($VarID,$CatID );
    IPS_SetIdent($VarID,$ident);
    if($script){
        $SkriptID=IPS_CreateScript(0);
        IPS_SetName($SkriptID,'control');
        IPS_SetParent($SkriptID,$VarID);
        IPS_SetHidden($SkriptID, True);
        IPS_SetScriptContent($SkriptID, $script);
        IPS_SetVariableCustomAction($VarID, $SkriptID);
        if ($profil!=''){
            IPS_SetVariableCustomProfile($VarID, $profil);
        }
        else    
        IPS_SetVariableCustomProfile($VarID, 'Time_4h');   
    }
    else {
        IPS_SetHidden($VarID, True);
    }
    return($VarID);    
}

private function CreateWahlVar($ident,$name,$icon,$par){
    $ID=$this->RegisterVariableBoolean($ident,$name,$icon);//
    $this->RegisterPropertyBoolean($ident, FALSE); 
    IPS_SetPosition($ID, 10);
    $this->EnableAction($ident);
    IPS_SetParent($ID,$par );
    return($ID);
}
        

private function FindTargetStatusofDevices() {
// ID der zu steuernden Instanz ermitteln    
    $ZielID= $this->ReadPropertyInteger('idLCNInstance');
//Children dieser Instanz ermitteln    
    $ID_Children=IPS_GetChildrenIds($ZielID);
//Children durchsuchen
    for($i=0;$i<=count($ID_Children)-1;$i++){
//Falls "Status" gefunden wird
        if(IPS_GetName($ID_Children[$i])=="Status"){
            $test_variable=$ID_Children[$i];
            IPS_LogMessage("AutoSwitch_FindTargetStatusofDevices","Variable = "
                .$ID_Children[$i]." Typ = ".$test_variable['VariableType']);
            return($test_variable); 
        }
        else {
            
        }
                
    }
    return(-1);
}

private function CheckEvent($script) {
    $EventID=@IPS_GetObjectIDByIdent('WatchEvent', $this->InstanceID);
    if($EventID){
        IPS_DeleteEvent($EventID);
    }    
    $ID=$this->FindTargetStatusofDevices();
    $EventID=$this->RegisterEvent('WatchEvent', $ID, $script);
    //IPS_SetEventActive($EventID, FALSE);
}
} 
?>
