<?
//Modul überwacht die letzte Aktualisierung bei einer Variable und startet ein 
//Skript, falls die Zeit der letzten zur aktuellen Aktualisierung kleiner gleich 
//einem Wert ist
class PIIOC extends IPSModule {
  protected $RelStore=0;  
  
  public function Create() {
    parent::Create();
    //$this->RegisterPropertyInteger('idLCNInstance', 0);
    $this->RegisterPropertyInteger('RelNr', 1);
    $this->RegisterPropertyString('name', '');
    $this->RegisterPropertyInteger('RelStore',0);
    $this->RegisterPropertyBoolean('Status', FALSE);
    $this->RegisterVariableBoolean('Status','Status','~Switch');//
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
    $statusID = $this->RegisterVariableBoolean('Status','Status','~Switch');//
 //   $status=$this->RegisterPropertyBoolean('Status', FALSE);
    $instID=IPS_GetParent($statusID);
//    $this->RegisterPropertyInteger('RelStore',0);
    
    //$this->RegisterPropertyInteger('idLCNInstance', 0); //Id der zu beobachtenden Variable
 //   $this->RegisterPropertyInteger('RelNr', 0);	
 //   $this->RegisterPropertyString('name', '');
    IPS_SetIcon($this->GetIDForIdent('Status'), 'Bulb');
    
    // Aktiviert die Standardaktion der Statusvariable
    $this->EnableAction("Status");
    
    
    if($this->ReadPropertyInteger('RelNr')!=0){ 
        $Name="Relais-".$this->ReadPropertyInteger('RelNr')." (".$this->ReadPropertyString('name').")"; 
        IPS_SetName($instID, $Name);  
    //	$this->RegisterTimer('OnVariableUpdate', 0, 'DBLC_Check($id)');
    }
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
 public function RequestAction($ident, $value) {
 
//ID und Wert von "Status" ermitteln
      //$statusID=$this->ReadPropertyBoolean('Status');
      //$status=GetValue($statusID);    
//ID der Instanz ermitteln   
      //$lcn_instID=$this->ReadPropertyInteger('idLCNInstance');	
//Lämpchen Nr. ermitteln
      
      //SetValue($this->RelStore, $RelNo);
      if($value){
        //LCN_SetLamp($lcn_instID,$lampNo,'E');
        $result=$this->set();
      }
      else{
        //LCN_SetLamp($lcn_instID,$lampNo,'A');
        $result=$this->clear();
      }
//Neuen Wert in die Statusvariable schreiben
      IPS_LogMessage('PIIOC', "ReadBack=".intval($result));
      if($result){
          SetValue($this->GetIDForIdent($ident), $value);
          IPS_LogMessage('PIIOC', "Befehl erfolgreich ausgeführt!");
      }
      else {
          IPS_LogMessage('PIIOC', "Pin-Änderung konnte nicht verifiziert werden!");
          
      }
      
}
  
public function set() {
    $RelStore=$this->setchannel();
    //$RelStore= $this->RelStore;
    shell_exec("/usr/local/bin/gpio write ".$RelStore." 0"); 
    IPS_LogMessage('PIIOC', "/usr/local/bin/gpio write ".$RelStore." 0");
    //IPS_LogMessage('PIIOC', "ReadBack=".intval($this->readback($RelStore)));
    if(($this->readback($RelStore))==0)
        return 1;
    else
        return 0;
}

public function clear() {
    $RelStore=$this->setchannel();
    //$RelStore= $this->RelStore;
    shell_exec("/usr/local/bin/gpio write ".$RelStore." 1");
    IPS_LogMessage('PIIOC', "/usr/local/bin/gpio write ".$RelStore." 1");
    //IPS_LogMessage('PIIOC', "ReadBack=".intval($this->readback($RelStore)));
    if($this->readback($RelStore))
        return 1;
    else
        return 0;
}
protected function readback($RelNo) {
    $result= intval(shell_exec("/usr/local/bin/gpio read ".$RelNo));
    return ($result);
}

protected function setchannel(){
    $RelNo=$this->ReadPropertyInteger('RelNr');
    switch ($RelNo){
          case 1 : $RelNo=2; break;
          case 2 : $RelNo=3; break;
          case 3 : $RelNo=4; break;
          case 4 : $RelNo=5; break;
          case 5 : $RelNo=6; break;
          case 6 : $RelNo=1; break;
          case 7 : $RelNo=0; break;
          case 8 : $RelNo=8; break;
          
      }
      
      $this->RelStore=$RelNo;
      return($RelNo);
}

public function Check() {
    if(IPS_SemaphoreEnter('LCNLA', 1000)) {
      
        


       IPS_SemaphoreLeave('LCNLA');
     } 
     else {
      IPS_LogMessage('LCNLA', 'Semaphore Timeout');
    }
   }
} 
?>
