<?
//Modul schaltet einen Ausgang (LCN-Ausgang, LCN-Lämpchen, LCN-Relais, entfernte Variable (JSON Zugriff) 


class Schalter extends IPSModule {
  
  
    
    
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('Auswahl', 0); //Id der zu beobachtenden Variable
    $this->RegisterPropertyInteger('idLCNInstance', 0);
    $this->RegisterPropertyInteger('LaempchenNr', 0);
    $this->RegisterPropertyInteger('Rampe', 2);
    $this->RegisterPropertyString('IPAddress', '');
    $this->RegisterPropertyString('Password', '');
    $this->RegisterPropertyString('ZielID', '');
    $this->RegisterPropertyString('Name','');
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
    $statusID = $this->RegisterVariableBoolean('Status','Status','~Switch');//
    $status=$this->RegisterPropertyBoolean('Status', FALSE);
    $this->RegisterPropertyInteger('Auswahl', 0); //Id der zu beobachtenden Variable
    $this->RegisterPropertyInteger('idLCNInstance', 0);
    $this->RegisterPropertyInteger('LaempchenNr', 0);
    $this->RegisterPropertyInteger('Rampe', 2);
    $this->RegisterPropertyString('IPAddress', '');
    $this->RegisterPropertyString('Password', '');
    $this->RegisterPropertyString('ZielID', '');
    $this->RegisterPropertyString('Name','');
    IPS_SetIcon($this->GetIDForIdent('Status'), 'Bulb');
    
    // Aktiviert die Standardaktion der Statusvariable
    $this->EnableAction("Status");
    $this->GetConfigurationForm();
    //if($this->ReadPropertyInteger('idLCNInstance')!=0){  
    //	$this->RegisterTimer('OnVariableUpdate', 0, 'DBLC_Check($id)');
   // }
  }
  /*
  protected function RegisterTimer($ident, $interval, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
      IPS_DeleteEvent($id);
      $id = 0;
    }
    if (!$id) {
      $id = IPS_CreateEvent(0);
      IPS_SetEventTrigger($id, 0, $this->ReadPropertyInteger('idLCNInstance')); //Bei Update von der gewählten Variable 
      IPS_SetEventActive($id, true);             //Ereignis aktivieren
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
    }
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, "\$id = \$_IPS['TARGET'];\n$script;");
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
  }*/
 public function GetConfigurationForm() {
     
     $status_entry=''; 
     $elements_entry0='{ "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 }
          ]
        }';
     $elements_entry1='{ "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 }
          ]},
          { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
          { "type": "NumberSpinner", "name": "Rampe", "caption": "Sekunden" },
          { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     $elements_entry2='{ "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 }
          ]},
          { "name": "idLCNInstance", "type": "SelectInstance", "caption": "LCN Instanz" },
          { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
          
     
     $elements_entry3='{ "name": "Auswahl", "type": "Select", "caption": "Schalt-Typ", 
        "options":[
            { "label": "LCN Ausgang", "value": 1 },
            { "label": "LCN Relais", "value": 2 },
            { "label": "LCN Lämpchen", "value": 3 },
            { "label": "JSON Fernzugriff", "value": 4 }
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
            { "label": "JSON Fernzugriff", "value": 4 }
          ]},
          { "type": "ValidationTextBox", "name": "IPAddress", "caption": "Host"},
          { "type": "PasswordTextBox", "name": "Password", "caption": "Passwort" },
          { "type": "ValidationTextBox", "name": "ZielID", "caption": "Ziel ID"},
          { "type": "ValidationTextBox", "name": "Name", "caption": "Bezeichnung"}';
     
     $action_entry='';
     $action_entry1='{ "type": "Label", "label": "Bitte die zu steuernde Instanz wählen" },
          { "type": "Button", "label": "An", "onClick": "Schalter_SetOn($id);" },
          { "type": "Button", "label": "Aus", "onClick": "Schalter_SetOff($id);" }';
     
     $wahl=$this->ReadPropertyInteger('Auswahl');
     switch($wahl){
         case 0:  $elements_entry=$elements_entry0; break;
         case 1:  $elements_entry=$elements_entry1; break;
         case 2:  $elements_entry=$elements_entry2; break;
         case 3:  $elements_entry=$elements_entry3; break;
         case 4:  $elements_entry=$elements_entry4; break;
     }
     if($this->ReadPropertyInteger('idLCNInstance')>0)
       $action_entry=$action_entry1;  
         
     $form='{ "status":['.$status_entry.'],"elements":['.$elements_entry.'],"actions":['.$action_entry.'],}';
     return $form;
      
}   
 
 public function RequestAction($ident, $value) {
 
//ID und Wert von "Status" ermitteln
      //$statusID=$this->ReadPropertyBoolean('Status');
      //$status=GetValue($statusID);    
//ID der Instanz ermitteln   
      //$lcn_instID=$this->ReadPropertyInteger('idLCNInstance');	
//Lämpchen Nr. ermitteln
      //$lampNo=$this->ReadPropertyInteger('LaempchenNr');
//Auswertung 
      //IPS_LogMessage('LCNLA',"Starte.....................");
      //IPS_LogMessage('LCNLA',"ident=".$ident);
      //IPS_LogMessage('LCNLA',"value=".$value);
//Überprüfen Status und sende Befehl an LCN_Instanz
      //if($value){
      //  LCN_SetLamp($lcn_instID,$lampNo,'E');  
      //}
      //else{
      //  LCN_SetLamp($lcn_instID,$lampNo,'A');  
     // }
      $this->Set($value);
//Neuen Wert in die Statusvariable schreiben
      SetValue($this->GetIDForIdent($ident), $value);
}

public function SetOn() {
      $this->Set(True);
      SetValue($this->GetIDForIdent("Status"), True);
      }
public function SetOff() {
      $this->Set(False);
      SetValue($this->GetIDForIdent("Status"), False);
      }
     
public function Set(Bool $value) {
    if(IPS_SemaphoreEnter('Switch', 1000)) {
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
              break;
          case 2: $instID=$this->ReadPropertyInteger('idLCNInstance');
              LCN_SwitchRelay($instID, $value);
              break;
          case 3: $lcn_instID=$this->ReadPropertyInteger('idLCNInstance');
              $lampNo=$this->ReadPropertyInteger('LaempchenNr');
              if($value){
                LCN_SetLamp($lcn_instID,$lampNo,'E');  
              }
              else{
                LCN_SetLamp($lcn_instID,$lampNo,'A');  
              }
              break;
          case 4: break;
          default: break;
      }
      SetValue($this->GetIDForIdent("Status"), $value);
//ID und Wert von "Status" ermitteln
      //$statusID=$this->ReadPropertyBoolean('Status');
      //$status=GetValue($statusID);    
//ID der Instanz ermitteln   
      //$lcn_instID=$this->ReadPropertyInteger('idLCNInstance');	
//Lämpchen Nr. ermitteln
      //$lampNo=$this->ReadPropertyInteger('LaempchenNr');
//Auswertung 
      //IPS_LogMessage('LCNLA',"Starte.....................");
//Überprüfen Status und sende Befehl an LCN_Instanz
      //if($status){
      //  LCN_SetLamp($lcn_instID,$lampNo,'E');  
      //}
      //else{
      //  LCN_SetLamp($lcn_instID,$lampNo,'A');  
     // }
        


       IPS_SemaphoreLeave('Switch');
     } 
     else {
      IPS_LogMessage('Switch', 'Semaphore Timeout');
    }
   }
} 
?>
