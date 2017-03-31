<?
//Modul überwacht die letzte Aktualisierung bei einer Variable und startet ein 
//Skript, falls die Zeit der letzten zur aktuellen Aktualisierung kleiner gleich 
//einem Wert ist
class LCNLA extends IPSModule {
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('idLCNInstance', 0);
    $this->RegisterPropertyInteger('LaempchenNr', 1);
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
    $statusID = $this->RegisterVariableBoolean('Status','Status','~Switch');//
    $status=$this->RegisterPropertyBoolean('Status', FALSE);
    $this->RegisterPropertyInteger('idLCNInstance', 0); //Id der zu beobachtenden Variable
    $this->RegisterPropertyInteger('LaempchenNr', 0);	
    IPS_SetIcon($this->GetIDForIdent('Status'), 'Bulb');
    
    // Aktiviert die Standardaktion der Statusvariable
    $this->EnableAction("Status");
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
 public function RequestAction($Status, $value) {
 
    Check();
    
 
}
  public function Check() {
    if(IPS_SemaphoreEnter('LCNLA', 1000)) {
        
//ID und Wert von "Status" ermitteln
      $statusID=$this->ReadPropertyBoolean('Status');
      $status=GetValue($statusID);    
//ID der Instanz ermitteln   
      $lcn_instID=$this->ReadPropertyInteger('idLCNInstance');	
//Lämpchen Nr. ermitteln
      $lampNo=$this->ReadPropertyInteger('LaempchenNr');
//Auswertung 
      IPS_LogMessage('LCNLA',"Starte.....................");
//Überprüfen Status und sende Befehl an LCN_Instanz
      if($status){
        LCN_SetLamp($lcn_instID,$lampNo,'E');  
      }
      else{
        LCN_SetLamp($lcn_instID,$lampNo,'A');  
      }
        


       IPS_SemaphoreLeave('LCNLA');
     } 
     else {
      IPS_LogMessage('LCNLA', 'Semaphore Timeout');
    }
   }
} 
?>
