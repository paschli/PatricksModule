<?
//Modul schaltet einen Ausgang (LCN-Ausgang, LCN-Lämpchen, LCN-Relais, entfernte Variable (JSON Zugriff) 


class AutSw extends IPSModule {
  
  
  //var $jsontest=0;  
    
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('Auswahl', 0); //Auswahl des Typs
    $this->RegisterPropertyInteger('idLCNInstance', 0); //ID der zu schaltenden Instanz
    $this->RegisterPropertyInteger('LaempchenNr', 0); //Falls es Lämpchen sind
    $this->RegisterPropertyInteger('idLightInstance', 0); //Falls es Lämpchen sind
    $this->RegisterPropertyInteger('Rampe', 2); // Rampe für das Schalten eines LCN Ausgangs
    $this->RegisterPropertyString('IPAddress', ''); //IP Adesse für remote schalten eines anderen IP-Symcon
    $this->RegisterPropertyString('Password', '');// Passwort für JSON-Verbindung
    $this->RegisterPropertyInteger('ZielID', 0);// ID des zu schaltenden entfernten Objekts
    $this->RegisterPropertyString('Name','');//Otionaler Name für die erstellte Instanz
    $this->RegisterPropertyInteger('State', 0); //Status der Instanz
    $this->RegisterPropertyBoolean('AutoOff_Switch', FALSE);
    $this->RegisterPropertyBoolean('Timer_Switch', FALSE);
    $this->RegisterPropertyBoolean('WatchTarget',FALSE);
    $this->RegisterPropertyBoolean('SelAutoOff',FALSE);
    $this->RegisterPropertyBoolean('SelTimer',FALSE);
    $this->RegisterPropertyInteger('SliderAnz',0);
    $this->RegisterPropertyBoolean('TimerMsg',FALSE);
    $this->RegisterPropertyBoolean('AutoTime',FALSE);
    $this->RegisterVariableBoolean('Status','Status','~Switch');//
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
    $this->CreateAnzVar('SetLaufzeit','Set Laufzeit',$CatID,20,'Hourglass','<?SetValue($_IPS["VARIABLE"],$_IPS["VALUE"]); ?>','');
//Laufzeit Anzeige erstellen
    $this->CreateAnzVar('Laufzeit','Laufzeit',$instID,10,'Hourglass','','');
//Wahlschalter "AutoOff" erstellen
    $this->CreateWahlVar('AutoOff_Switch', 'Auto Off', '~Switch', $CatID, 10);
//Wahlschalter "Timer" erstellen        
    $this->CreateWahlVar('Timer_Switch', 'Timer', '~Switch', $CatID, 30);
    }
//Aktion, falls zu schaltendes Objekt von anderen Instanzen oder Schaltern geschaltet wird
    $scriptDevice="\$id = \$_IPS['TARGET'];\n".
                    'AutSw_EventTrigger($id,$id, GetValueBoolean(IPS_GetEvent($_IPS["EVENT"])["TriggerVariableID"]));';
    if($this->ReadPropertyInteger('idLCNInstance'))
        $typ= $this->ReadPropertyInteger('Auswahl');
    else if($this->ReadPropertyInteger('ZielID'))
        $typ= $this->ReadPropertyInteger('Auswahl');
    else 
        $typ=0;
    
    
    if($this->ReadPropertyBoolean('SelAutoOff')){
        $this->RegisterTimer('AutoOffTimer', 60, "\$id = \$_IPS['TARGET'];\n".'AutSw_AutoOff($id);');
        $TimerID=$this->GetIDForIdent('AutoOffTimer');
        IPS_SetEventActive($TimerID, false);
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
        IPS_SetHidden($AutoOffID, FALSE);
        $LaufzeitID= IPS_GetObjectIDByIdent('SetLaufzeit', $CatID);
        IPS_SetHidden($LaufzeitID, FALSE);
        
    }
    else{
        $TimerID=@$this->GetIDforIdent('AutoOffTimer');
        if($TimerID){
            IPS_SetEventActive($TimerID, False);   
        }
        $AutoOffID=IPS_GetObjectIDByIdent('AutoOff_Switch', $CatID);
        $LaufzeitID= IPS_GetObjectIDByIdent('SetLaufzeit', $CatID);
        IPS_SetHidden($AutoOffID, True);
        IPS_SetHidden($LaufzeitID, TRUE);
    }
    
    if($this->ReadPropertyBoolean('SelTimer')){
        $TimerSelID=IPS_GetObjectIDByIdent('Timer_Switch', $CatID);
        IPS_SetHidden($TimerSelID, FALSE);
    }
    else{
        $TimerSelID=IPS_GetObjectIDByIdent('Timer_Switch', $CatID);
        IPS_SetHidden($TimerSelID, TRUE);
    }
    
 /*   if(!$this->ReadPropertyBoolean('WatchTarget')||!$this->ReadPropertyBoolean('SelAutoOff')){
        $typ=0;
        $EventID=@IPS_GetObjectIDByIdent('WatchEvent', $this->InstanceID);
        if($EventID){
            IPS_SetEventActive($EventID,FALSE);
        }
    }    
 */       
//Zusätzliche Aktionen für spezielle Typen    
    switch($typ){
            case 0: //falls Instanz nicht gewählt wurde
                break;
            case 1: //falls Instanz LCN Ausgang
                $this->CheckEvent($scriptDevice);//prüft ob Event vorhanden ist und setzt die Überwachung auf den Status der Instanz
                if(!@IPS_GetObjectIDByIdent('SliderAnz', $instID)){
                    $script='<?'.chr(13).
                            'SetValue($_IPS["VARIABLE"], $_IPS["VALUE"]);'.chr(13).
                            'LCN_SetIntensity('.$this->ReadPropertyInteger("idLCNInstance").', $_IPS["VALUE"],0);'.chr(13).
                            '?>';
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
            case 6:
                break;
            case 7:
                break;
            case 8:
                break;
            default:
                break;
        }
    if($typ!=1){
        if($SliderID=@IPS_GetObjectIDByIdent('SliderAnz', $instID)){
            if($scriptID=@IPS_GetObjectIDByName('control', $SliderID))
                IPS_DeleteScript ($scriptID, TRUE);
            IPS_DeleteVariable($SliderID);
        }
            
    }     
    
    $this->GetConfigurationForm(); 
  }
  
 

 public function GetConfigurationForm() {
     
    $status_entry='{ "code": 101, "icon": "inactive", "caption": "Instanz wird erstellt" },
             { "code": 102, "icon": "active", "caption": "Instanz aktiv" },
             { "code": 200, "icon": "error", "caption": "Instanz fehlerhaft" }'; 
     
     /*,
            { "label": "PIGPIO_Output", "value": 8 }*/
    $elements_entry_device='
        { "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 },
            { "label": "Schalter", "value": 5 },
            { "label": "PIIOC", "value": 6 },
            { "label": "Sonoff", "value": 7 },
            { "label": "PI_GPIO_Output", "value": 8}
          ]
        }';
    $elements_entry_lcnOutput=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
        { "type": "NumberSpinner", "name": "Rampe", "caption": "Sekunden" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
    $elements_entry_lcnRelais=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
    
    $elements_entry_Sonoff=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "Sonoff Instanz" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
    
    $elements_entry_PIGPIO=',
        { "name": "idLCNInstance", "type": "SelectInstance", "caption": "PIGPIO_Output Instanz" },
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
        { "name": "idLightInstance", "type": "SelectInstance", "caption": "Instanz für Lamp Status" },
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
    $elements_entry_jsonZugriff=',
        { "type": "ValidationTextBox", "name": "IPAddress", "caption": "Host"},
        { "type": "PasswordTextBox", "name": "Password", "caption": "Passwort" },
        { "type": "NumberSpinner", "name": "ZielID", "caption": "Ziel ID"},
        { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
    $elements_entry_AutoOff=',{ "type": "CheckBox", "name": "SelAutoOff", "caption": "Countdown-Timer-Funktion hinzufügen" }';
    
    $elements_entry_Timer=',{ "type": "CheckBox", "name": "SelTimer", "caption": "Timer Funktion" }';
    
    $elements_entry_AutoOffWatch=',{ "type": "CheckBox", "name": "WatchTarget", "caption": "Ziel überwachen" }'; 
    
    $elements_entry_TimerMsg=',{ "type": "CheckBox", "name": "TimerMsg", "caption": "Nachricht bei Timer Event" }'; 
            
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
        case 6:  $elements_entry=$elements_entry_device.$elements_entry_jsonZugriff; break;
        case 7:  $elements_entry=$elements_entry_device.$elements_entry_Sonoff; break;
        case 8:  $elements_entry=$elements_entry_device.$elements_entry_PIGPIO; break;
        
    }
//Option für WatchEvent - geht nur bei LCN-Instanz, LCN-Relais, Switch_Modul 
    if($this->ReadPropertyBoolean('SelAutoOff')&&($wahl!=3)&&($wahl!=4)&&($wahl!=6)){
        $elements_entry_AutoOff=$elements_entry_AutoOff.$elements_entry_AutoOffWatch; 
    } 
    else{
        $elements_entry_AutoOff=$elements_entry_AutoOff;
    }
//Option für AutoOff und Timer CheckBoxen        
    if($this->ReadPropertyInteger('idLCNInstance')){
        $action_entry=$action_entry1;
        $elements_entry=$elements_entry.$elements_entry_AutoOff.$elements_entry_Timer;
    }
    else if((($wahl==4)||($wahl==6))&&($this->ReadPropertyInteger('ZielID')>0)){
        if($this->checkVerb($wahl)==1){
            $action_entry=$action_entry1;
            $elements_entry=$elements_entry.$elements_entry_AutoOff.$elements_entry_Timer;
        }
    }
    else{
        IPS_LogMessage("AutoSwitch_GetConfigurationForm","Konfiguration nicht vollständig!");
        $action_entry='';
    }
    if($this->ReadPropertyBoolean('SelTimer'))
        $elements_entry=$elements_entry.$elements_entry_TimerMsg;
    
    $form='{ "status":['.$status_entry.'],"elements":['.$elements_entry.'],"actions":['.$action_entry.'],}';
    return $form;
      
} 

public function EventTrigger(int $par,bool $value) {
    IPS_LogMessage("AutoSwitch_EventTrigger","Name: ".IPS_GetName($par)." Value: ".$value);
    $par= IPS_GetParent(($this->GetIDForIdent("Status")));
    $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
    if(IPS_GetObject($IDLaufz)['ObjectIsHidden']){
        $this->Set($value,TRUE);
        IPS_LogMessage("AutoSwitch_EventTrigger","Set ausführen mit Anzeige");
        return 1;
    }
    IPS_LogMessage("AutoSwitch_EventTrigger","Set ausführen ohne Anzeige");
    $this->Set($value,False);  
    
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
        IPS_LogMessage("AutoSwitch_RequestAction","AutoOff Einstellung geändert");
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
         IPS_LogMessage("AutoSwitch_RequestAction","Zeitplan Erreignis");
         SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value);
         $this->TimerSwitchAction($CatID); 
        //$this->Set($value);
     }
     else if($ident=='Status'){
        IPS_LogMessage("AutoSwitch_RequestAction","Status-Variable geändert: ".$value);
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
            IPS_LogMessage("AutoSwitch_RequestAction","Laufzeit zeigen");
        }
        else {
            IPS_SetHidden($IDLaufz, TRUE);
            IPS_LogMessage("AutoSwitch_RequestAction","Laufzeit verbergen");
            
        }
        $this->Set($value,TRUE);
        
     }
     else if($ident=='SliderAnz'){
         IPS_LogMessage("AutoSwitch_RequestAction","Slider Anzeige ".$value);
        SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value); 
        $instID=$this->ReadPropertyInteger('idLCNInstance');
        LCN_SetIntensity($instID, $value, 0);
     }
     else if($ident=="AutoTime"){
         IPS_LogMessage("AutoSwitch_RequestAction","Zeitplan verstellen");
         SetValue(IPS_GetObjectIDByIdent($ident, $CatID),$value);
         if($value){
            $this->AutoTimeUpdate($CatID,1);
         }
         else{
            $this->AutoTimeUpdate($CatID,0);
         }
         
               
         
     }
     
//Neuen Wert in die Statusvariable schreiben
      
}
public function Toggle(){
    $status= GetValueBoolean($this->GetIDForIdent('Status'));
    if($status)
        $this->SetOff ();
    else
        $this->SetOn ();
      
}

public function SetOn() {
      $this->Set(True,TRUE);
 /*     if($this->ReadPropertyBoolean('TimerMsg')){
          $par= IPS_GetParent(($this->GetIDForIdent('Status')));
          WFC_PushNotification(33722, "Info AutoSwitchModul", IPS_GetName($par) . " erfolgreich eingeschaltet", "", 0); 
      } */          
}

public function SetOff() {
      $this->Set(False,TRUE);
/*      if($this->ReadPropertyBoolean('TimerMsg')){
          $par= IPS_GetParent(($this->GetIDForIdent('Status')));
          WFC_PushNotification(33722, "Info AutoSwitchModul", IPS_GetName($par) . " erfolgreich ausgeschaltet", "", 0); 
      }*/
}

private function checkVerb($wahl) {
      $password= $this->ReadPropertyString('Password'); 
      $IPAddr= $this->ReadPropertyString('IPAddress');
      $TargetID=(integer) $this->ReadPropertyInteger('ZielID');
      $mes="http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/";
      //IPS_LogMessage("AutoSwitch_Check","Aufruf:".$mes."Target ID".$TargetID);
      
      try {
          $rpc =@ new JSONRPC("http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/");
          if($wahl==4)
            @$rpc->GetValue($TargetID);
          else if($wahl==6)
            @$rpc->IPS_GetKernelVersion();
          else{
            IPS_LogMessage("AutoSwitch_checkVerb","Verbindung konnte nicht verifiziert werden! Aufrufparameter falsch!");
            return 0;
          }
        } 
      catch (JSONRPCException $e) {
          IPS_LogMessage("AutoSwitch_checkVerb","Verbindung konnte nicht verifiziert werden! RPC Problem!");
          //echo 'RPC Problem: ',  $e->getMessage(), "\n";
          return 0;
        } 
      catch (Exception $e) {
          IPS_LogMessage("AutoSwitch_checkVerb","Verbindung konnte nicht verifiziert werden! IP- oder Passwort Problem!");
          //echo 'Server Problem: ',  $e->getMessage(), "\n";
          return 0;
        }
        IPS_LogMessage("AutoSwitch_checkVerb","Verbindung verifiziert!");
        return 1;
        
           
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
        $this->Set(FALSE,TRUE);
    } 
    if(!GetValueBoolean($this->GetIDForIdent('Status'))){
        IPS_SetHidden($IDLaufz, TRUE);
        $timerID= IPS_GetObjectIDByIdent('AutoOffTimer', $par);
        IPS_SetEventActive($timerID, FALSE);
        
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
    IPS_LogMessage("AutoSwitch_Set_Timer","Laufzeit:".$Laufzeit);
    $par= IPS_GetParent(($this->GetIDForIdent("Status")));
    $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
    $TimerID=@$this->GetIDForIdent('AutoOffTimer');
    if($Laufzeit>0){
        if($TimerID){
            IPS_SetEventActive($TimerID, TRUE);
        }
        SetValueInteger($IDLaufz, $Laufzeit);
        IPS_SetHidden($IDLaufz, FALSE);
        $this->Set(TRUE,FALSE);
    }
    else{
        $this->Set(FALSE,TRUE);
    }
    IPS_LogMessage("AutoSwitch_Set_Timer","Funktion Beendet");
  }
  
public function Set(bool $value, bool $anzeige) {
    $func="Set";
    
    if(IPS_SemaphoreEnter('AutoSwitch_Set', 5000)) {
      $par= IPS_GetParent(($this->GetIDForIdent('Status')));
      $name= IPS_GetName($par);
      $CatID =IPS_GetCategoryIDByName('Konfig', $par);
      $value_dim=0;
      $typ= $this->ReadPropertyInteger('Auswahl');
      IPS_LogMessage("AutoSwitch_".$func,"Set für ".$name." aufgerufen mit ". $this->boolToString($value)."!");
      $EventID=@IPS_GetObjectIDByIdent('WatchEvent', $this->InstanceID);
      if($EventID){
          IPS_SetEventActive($EventID,false);
          IPS_LogMessage("AutoSwitch_".$func,"WatchEvent deaktivieren!");
      }  
      switch($typ){
        case 0: 
            $result=0;
        break;

        case 1: 
          for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_LCN_Dim($value); 
              IPS_LogMessage('AutoSwitch_Set_LCN_Out', 'Aktion ausgeführt= '.$i."-mal");
              if($result==1)
                  break;
          }  
        break;
          
        case 2: 
          for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_LCN_Rel($value);
              IPS_LogMessage('AutoSwitch_Set_LCN_Relais', 'Aktion ausgeführt= '.$i."-mal");
              if($result==1)
                  break;
          }  

        break;
        
        case 3: 
          $this->Set_LCN_Lamp($value); 
            $result=1;
          break;
      
        case 4: 
          for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_JSON($value); 
              IPS_LogMessage('AutoSwitch_Set_JSON', 'Aktion ausgeführt= '.$i."-mal");
              if($result==1)
                  break;
          }
        break;
        
        case 5: /*$lcn_instID=$this->ReadPropertyInteger('idLCNInstance');
          if($value){
              IPS_LogMessage("AutoSwitch_Set","Aufruf AN Schalter_Set ID=".$lcn_instID);
              Schalter_Set($lcn_instID,1);  
          }
          else{
              IPS_LogMessage("AutoSwitch_Set","Aufruf AUS Schalter_Set ID=".$lcn_instID);
              Schalter_Set($lcn_instID,0);
          }
          SetValue($this->GetIDForIdent("Status"), $value);*/
          $this->Set_Schalter($value);
          $result=1;
           
        break; 
    
        case 6: 
          /*$password= $this->ReadPropertyString('Password'); 
          $IPAddr= $this->ReadPropertyString('IPAddress');
          $TargetID=(integer) $this->ReadPropertyInteger('ZielID');
          $mes="http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/";
          IPS_LogMessage("AutoSwitch_Set","Aufruf".$mes);
          IPS_LogMessage("AutoSwitch_Set","Target ID".$TargetID);
          try{
              $rpc = new JSONRPC("http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/");
              if($value){
                  //IPS_LogMessage(Modul,"Value = True => Relais An");
                  $rpc->PIIOC_set($TargetID);
              }           
              else{
                  //IPS_LogMessage(Modul,"Value = False => Relais Aus");
                  $rpc->PIIOC_clear($TargetID);
              }

          }
          catch (JSONRPCException $e) {
              echo 'RPC Problem', "\n";
              IPS_SemaphoreLeave('AutoSwitch_Set');
              IPS_LogMessage('AutoSwitch_Set', 'RPC Fehler');
              return 0;
          } 
          catch (Exception $e) {
             echo 'Server Problem',"\n";
             IPS_SemaphoreLeave('AutoSwitch_Set');
             IPS_LogMessage('AutoSwitch_Set', 'Verbindungsfehler');
             return 0;
          }

          SetValue($this->GetIDForIdent("Status"), $value);
          IPS_LogMessage('AutoSwitch_Set', 'Verbindung erfolgreich!');*/
          $this->Set_PIIOC($value);
          $result=1;
        break;
      
        case 7:
          for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_Tasmota($value); 
              IPS_LogMessage('AutoSwitch_Set_Tasmota', 'Aktion ausgeführt= '.$i."-mal");
              if($result==1)
                  break;
          }
        break;
        
        case 8:
          for($i = 1 ; $i <= 3 ; $i++){
              $result=$this->Set_PIGPIO($value);
              IPS_LogMessage('AutoSwitch_Set_PIGPIO', 'Aktion ausgeführt= '.$i."-mal");
              if($result==1)
                  break;
          }
        break;
        
        default: 
          $result=0;
        break;
      }
      if($result==1){
          IPS_LogMessage('AutoSwitch_Set', 'Aktion erfolgreich!');
          if($this->ReadPropertyBoolean('TimerMsg')){
              $value ? WFC_PushNotification(33722, "Info AutoSwitchModul", $name . " erfolgreich eingeschaltet", "", 0):
                       WFC_PushNotification(33722, "Info AutoSwitchModul", $name . " erfolgreich ausgeschaltet", "", 0);
          }
      }
      else{
          IPS_LogMessage('AutoSwitch_Set', 'Aktion fehlgeschlagen!');
          $wert=$this->boolToString($value);
          WFC_PushNotification(33722, "Info AutoSwitchModul", "Fehler bei SET für ".$name."/ Sollwert= ".$wert." / Typ=".$typ, "", 0);
          IPS_SemaphoreLeave('AutoSwitch_Set');
          exit();
      }
      if($EventID){
          IPS_SetEventActive($EventID,true);
          IPS_LogMessage("AutoSwitch_".$func,"WatchEvent aktivieren!");
      }    
      $AutoTimeID=@IPS_GetObjectIDByIdent('AutoTime', $CatID);
      if(($AutoTimeID)){
          if(GetValueBoolean($AutoTimeID))
            $this->AutoTimeUpdate($CatID,1);
      }
      
      if($anzeige){
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
            IPS_LogMessage("AutoSwitch_Set","Laufzeit zeigen");
        }
        else{
            IPS_SetHidden($IDLaufz, TRUE);
            IPS_LogMessage("AutoSwitch_Set","Laufzeit verbergen");
        }    
      }
      else {
        if(!$value){
           $IDLaufz= IPS_GetVariableIDByName('Laufzeit', $par);
           IPS_SetHidden($IDLaufz, TRUE);
           IPS_LogMessage("AutoSwitch_Set","Laufzeit verbergen"); 
        }
      }    
      IPS_SemaphoreLeave('AutoSwitch_Set');
     } 
     else {
      IPS_LogMessage('AutoSwitch_Set', 'Semaphore Timeout');
    }
   }

private function Set_LCN_Dim($value) {
    $instID=$this->ReadPropertyInteger('idLCNInstance');
    $dim_time= $this->ReadPropertyInteger('Rampe');
    $SliderID=@$this->GetIDForIdent('SliderAnz');
    if($value){
        LCN_SetIntensity($instID, 100, $dim_time);
        SetValueInteger($SliderID, 100);
    }
    else {
        LCN_SetIntensity($instID, 0, $dim_time);
        SetValueInteger($SliderID, 0);
    }
    if($dim_time){
        sleep($dim_time);
    }
    else {
        usleep(100000);
    }
    $status_id= $this->get_status_id($instID,'Status');
    $wert=$this->boolToString($status_id);
    IPS_LogMessage('AutoSwitch_Set_LCN_Dim', 'Status für '.$instID.' = '.$wert);
    if($status_id==$value){
        SetValue($this->GetIDForIdent("Status"), $status_id);
        return 1;
    }
    else {
        return 0;
    }
}   
   
private function Set_LCN_Rel($value) {
    $instID=$this->ReadPropertyInteger('idLCNInstance');
    LCN_SwitchRelay($instID, $value);
    $status_id= $this->get_status_id($instID,'Status');
    if($status_id==$value){
        SetValue($this->GetIDForIdent("Status"), $status_id);
        return 1;
    }
    else {
        return 0;
    }
    
}

private function Set_LCN_Lamp($value) {
    $lcn_instID=$this->ReadPropertyInteger('idLCNInstance');
    $lampNo=$this->ReadPropertyInteger('LaempchenNr');
    $check=0;
    if($this->ReadpropertyInteger('idLightInstance')){
        $check=1;
        $idcheckLamp=$this->ReadPropertyInteger('idLightInstance');
        IPS_LogMessage("AutoSwitch_Set_LCN_Lamp","Mit Check!");
    }
    
    for ($i = 0; $i < 3; $i++) {
        if($value){
            LCN_SetLamp($lcn_instID,$lampNo,'E');
            $lamp_status='E';
        }
        else{
            LCN_SetLamp($lcn_instID,$lampNo,'A');
            $lamp_status='A';
        }
        if(($check==0)||($this->Check_LCN_Lamp($idcheckLamp,$lampNo,$lamp_status))){
            IPS_LogMessage("AutoSwitch_Set_LCN_Lamp","Befehl erfolgreich");
            SetValue($this->GetIDForIdent("Status"), $value);
            break;
        }
        else {
            IPS_LogMessage("AutoSwitch_Set_LCN_Lamp","Befehl konnte nicht ausgeführt werden!");
        }
    }
    
}

private function Check_LCN_Lamp($idcheckLamp,$lampNo,$lamp_value) {
    
    foreach (IPS_GetChildrenIDs($idcheckLamp) as $element) {
        IPS_LogMessage("AutoSwitch_Check_LCN_Lamp","Checke:"'Tableau Licht '.(string)$lampNo);
        if(strstr(IPS_GetName($element),'Tableau Licht '.(string)$lampNo)){
            return 1;
        }
    }
    return 0;
}

private function Set_JSON($value) {
    $password= $this->ReadPropertyString('Password'); 
    $IPAddr= $this->ReadPropertyString('IPAddress');
    $TargetID=(integer) $this->ReadPropertyInteger('ZielID');
    $mes="http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/";
    IPS_LogMessage("AutoSwitch_Set","Aufruf".$mes);
    IPS_LogMessage("AutoSwitch_Set","Target ID".$TargetID);
    try {
        $rpc = new JSONRPC("http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/");
        if($value){
            //IPS_LogMessage(Modul,"Value = True => Relais An");
            $rpc->SetValue($TargetID, true);
        }           
        else{
            //IPS_LogMessage(Modul,"Value = False => Relais Aus");
            $rpc->SetValue($TargetID, false);
        }
    }
    catch (JSONRPCException $e) {
        echo 'RPC Problem', "\n";
        IPS_SemaphoreLeave('AutoSwitch_Set');
        IPS_LogMessage('AutoSwitch_Set', 'RPC Fehler');
        return 0;
    } 
    catch (Exception $e) {
       echo 'Server Problem',"\n";
       IPS_SemaphoreLeave('AutoSwitch_Set');
       IPS_LogMessage('AutoSwitch_Set', 'Verbindungsfehler');
       return 0;
    }

    $result=(bool)$rpc->GetValue($TargetID);
    if($result==$value){
        SetValue($this->GetIDForIdent("Status"), $result);
        return 1;
    }
    else {
        return 0;
    }
    
    
}

private function Set_Schalter($value) {
    $lcn_instID=$this->ReadPropertyInteger('idLCNInstance');
    if($value){
        IPS_LogMessage("AutoSwitch_Set","Aufruf AN Schalter_Set ID=".$lcn_instID);
        Schalter_Set($lcn_instID,1);  
    }
    else{
        IPS_LogMessage("AutoSwitch_Set","Aufruf AUS Schalter_Set ID=".$lcn_instID);
        Schalter_Set($lcn_instID,0);  
    }
    SetValue($this->GetIDForIdent("Status"), $value);
}

private function Set_PIIOC($value) {
    $password= $this->ReadPropertyString('Password'); 
    $IPAddr= $this->ReadPropertyString('IPAddress');
    $TargetID=(integer) $this->ReadPropertyInteger('ZielID');
    $mes="http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/";
    IPS_LogMessage("AutoSwitch_Set","Aufruf".$mes);
    IPS_LogMessage("AutoSwitch_Set","Target ID".$TargetID);
    try{
        $rpc = new JSONRPC("http://patrick".chr(64)."schlischka.de:".$password."@".$IPAddr.":3777/api/");
        if($value){
            //IPS_LogMessage(Modul,"Value = True => Relais An");
            $rpc->PIIOC_set($TargetID);
        }           
        else{
            //IPS_LogMessage(Modul,"Value = False => Relais Aus");
            $rpc->PIIOC_clear($TargetID);
        }

    }
    catch (JSONRPCException $e) {
        echo 'RPC Problem', "\n";
        IPS_SemaphoreLeave('AutoSwitch_Set');
        IPS_LogMessage('AutoSwitch_Set', 'RPC Fehler');
        return 0;
    } 
    catch (Exception $e) {
       echo 'Server Problem',"\n";
       IPS_SemaphoreLeave('AutoSwitch_Set');
       IPS_LogMessage('AutoSwitch_Set', 'Verbindungsfehler');
       return 0;
    }

    SetValue($this->GetIDForIdent("Status"), $value);
    IPS_LogMessage('AutoSwitch_Set', 'Verbindung erfolgreich!');
}

private function Set_Tasmota($value) {
    $instID=$this->ReadPropertyInteger('idLCNInstance');
    $value ? Tasmota_setPower($instID, 'POWER', 1) : Tasmota_setPower($instID, 'POWER', 0);
    sleep(1);
    $status_id= $this->get_status_id($instID,'POWER');
    
    if($status_id==$value){
        SetValue($this->GetIDForIdent("Status"), $status_id);
        return 1;
    }
    else {
        return 0;
    }
}

private function Set_PIGPIO($value) {
    $instID=$this->ReadPropertyInteger('idLCNInstance');
    I2GOUT_Set_Status($instID, $value);
    usleep(100000);
    $status_id= $this->get_status_id($instID,'Status');
    if($status_id==$value){
        SetValue($this->GetIDForIdent("Status"), $status_id);
        return 1;
    }
    else {
        return 0;
    }
    //return $result;
}
private function get_status_id($id, $name){
    $arr=IPS_GetChildrenIDs($id);
    $status_id=0;
    foreach($arr as $child){
        if(IPS_GetName($child)==$name) 
            $status_id=$child;
    }    
    return GetValueBoolean($status_id);
}

private function boolToString($boolVal){
  return ($boolVal ? 'true' : 'false');
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

private function CreateWahlVar($ident,$name,$icon,$par, $pos){
    $ID=$this->RegisterVariableBoolean($ident,$name,$icon);//
    //$this->RegisterPropertyBoolean($ident, FALSE); 
    IPS_SetPosition($ID, $pos);
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

private function TimerSwitchAction($CatID) {
    //Timer
    $T_Switch_Val=GetValue(IPS_GetObjectIDByIdent('Timer_Switch', $CatID));
    $eventScript="\$id = \$_IPS['TARGET'];\n".'$idp = IPS_GetParent($id);';
    
    $esOn="\n".'AutSw_SetOn($idp);';
    $esOff="\n".'AutSw_SetOff($idp);';
    
    $eventScriptOn=$eventScript.$esOn;
    $eventScriptOff=$eventScript.$esOff;
    
    if($this->ReadPropertyBoolean('SelTimer'))    
    if($T_Switch_Val){
        if(!@IPS_GetObjectIDByIdent('AutoTime', $CatID))
            $this->CreateWahlVar('AutoTime', 'Dämerungsautomatik', '~Switch', $CatID, 70);
        else {
            $AutoTimeID=@IPS_GetObjectIDByIdent('AutoTime', $CatID);
            IPS_SetHidden($AutoTimeID, FALSE);
        }
        $Set_1_ID=@IPS_GetObjectIDByIdent('Set_1', $CatID);
        if(!$Set_1_ID){
            $this->CreateTimeEvent('Set_1', $CatID, 40, $eventScriptOn);
        }
        else
            IPS_SetHidden ($Set_1_ID, FALSE);
        
        $Clear_1_ID=@IPS_GetObjectIDByIdent('Clear_1', $CatID);
        if(!$Clear_1_ID){
            $eventScript=$eventScript.$esOff;
            $this->CreateTimeEvent('Clear_1', $CatID, 50, $eventScriptOff);   
        }
        else
            IPS_SetHidden ($Clear_1_ID, FALSE);
        
        $Set_2_ID=@IPS_GetObjectIDByIdent('Set_2', $CatID);
        if(!$Set_2_ID){
            $this->CreateTimeEvent('Set_2', $CatID, 60, $eventScriptOn);   
        }
        else
            IPS_SetHidden ($Set_2_ID, FALSE);
        
        $Clear_2_ID=@IPS_GetObjectIDByIdent('Clear_2', $CatID);
        if(!$Clear_2_ID){
            $this->CreateTimeEvent('Clear_2', $CatID, 70, $eventScriptOff);   
        }
        else
            IPS_SetHidden ($Clear_2_ID, FALSE);  
    }
    else{
        $AutoTimeID=@IPS_GetObjectIDByIdent('AutoTime', $CatID);
        if($AutoTimeID){
            IPS_SetHidden($AutoTimeID, TRUE);
        }
            
        
        $Set_1_ID=@IPS_GetObjectIDByIdent('Set_1', $CatID);
        if($Set_1_ID){
            IPS_SetHidden ($Set_1_ID, TRUE);
            IPS_SetEventActive($Set_1_ID, FALSE);    
        }
        
        $Clear_1_ID=@IPS_GetObjectIDByIdent('Clear_1', $CatID);
        if($Clear_1_ID){
            IPS_SetHidden ($Clear_1_ID, TRUE);
            IPS_SetEventActive($Clear_1_ID, FALSE);  
        }
        
        $Set_2_ID=@IPS_GetObjectIDByIdent('Set_2', $CatID);
        if($Set_2_ID){
            IPS_SetHidden ($Set_2_ID, TRUE);
            IPS_SetEventActive($Set_2_ID, FALSE);
        }
        
        $Clear_2_ID=@IPS_GetObjectIDByIdent('Clear_2', $CatID);
        if($Clear_2_ID){
            IPS_SetHidden ($Clear_2_ID, TRUE);
            IPS_SetEventActive($Clear_2_ID, FALSE);
        }
    }
    
}

private function CreateTimeEvent($ident, $parentID, $Position, $content){
    $eid= IPS_CreateEvent(1);
    IPS_SetEventCyclic($eid, 3, 1, 127, 1, 0, 2);
    IPS_SetParent($eid, $parentID);
    IPS_SetIcon($eid, 'Clock');
    IPS_SetIdent($eid, $ident);
    IPS_SetName($eid, $ident);
    IPS_SetEventActive($eid, FALSE);
    IPS_SetPosition($eid, $Position);
    IPS_SetEventScript($eid, $content);  
 }

 private function AutoTimeUpdate($CatID, $value) {
//Dämmerungszeit Früh kopieren
IPS_LogMessage("AutoSwitch_AutoTimeUpdate","Start");
$ids=IPS_GetEventIDByName('Set_2', $CatID);
$idf=IPS_GetEventIDByName('Clear_1', $CatID);
if($value){ 
//Dämmerungszeit Früh
    $ID_LocationControl=IPS_GetObjectIDByName('Location Control', 0);
    //$ID_LocationControl=33556;
    IPS_LogMessage("AutoSwitch_AutoTimeUpdate","ID_Location=".$ID_LocationControl);
    $ID_Früh= IPS_GetObjectIDByIdent('Sunrise', $ID_LocationControl);
    IPS_LogMessage("AutoSwitch_AutoTimeUpdate","ID_Früh=".$ID_Früh);
    $timestamp = GetValueInteger($ID_Früh);
    $Stunde = date("H", $timestamp);
    $Minute = date("i", $timestamp);
    $Sekunde = date("s", $timestamp);
    $ids2=IPS_GetEventIDByName('Set_1', $CatID);
    IPS_LogMessage("AutoSwitch_AutoTimeUpdate","EventActive = "
                .(int)IPS_GetEvent($ids2)['EventActive']);
    if(IPS_GetEvent($ids2)['EventActive']){
        IPS_LogMessage("AutoSwitch_AutoTimeUpdate","Event = "
                .$idf." Zeit = ".$Stunde.":".$Minute.":".$Sekunde);
        IPS_SetEventCyclicTimeFrom($idf, $Stunde, $Minute, $Sekunde);
        IPS_SetEventActive($idf, TRUE);
    }
    else{
        IPS_SetEventActive ($idf, FALSE);
        IPS_LogMessage("AutoSwitch_AutoTimeUpdate","Clear Event!");
    }
    
//Dämmerungszeit Spät
    $ID_Spät=@IPS_GetObjectIDByIdent('CivilTwilightEnd', $ID_LocationControl);
    IPS_LogMessage("AutoSwitch_AutoTimeUpdate","ID_Spät=".$ID_Spät);
    $timestamp = GetValueInteger($ID_Spät);
    $Stunde = date("H", $timestamp);
    $Minute = date("i", $timestamp);
    $Sekunde = date("s", $timestamp);
    $ids=IPS_GetEventIDByName('Set_2', $CatID);
    $idf2=IPS_GetEventIDByName('Clear_2', $CatID);
    IPS_LogMessage("AutoSwitch_AutoTimeUpdate","EventActive = "
                .(int)IPS_GetEvent($idf2)['EventActive']);
    if(IPS_GetEvent($idf2)['EventActive']){
        IPS_LogMessage("AutoSwitch_AutoTimeUpdate","Event = "
                .$idf2." Zeit = ".$Stunde.":".$Minute.":".$Sekunde);
        IPS_SetEventCyclicTimeFrom($ids, $Stunde, $Minute, $Sekunde);
        IPS_SetEventActive($ids, TRUE);
    }
    else{
        IPS_SetEventActive ($ids, FALSE);
        IPS_LogMessage("AutoSwitch_AutoTimeUpdate","Clear Event!");
    }
    IPS_SetEventCyclicTimeFrom($ids, $Stunde, $Minute, $Sekunde);
    IPS_SetDisabled($idf, true);
    IPS_SetDisabled($ids, true);
//    IPS_SetEventActive($ids, TRUE);
}
else{
    IPS_SetDisabled($idf, false);
    //IPS_SetEventActive($idf, FALSE);
    IPS_SetDisabled($ids, false);
    //IPS_SetEventActive($ids, FALSE);
}
 
}
} 
?>
