<?
//Modul überwacht die letzte Aktualisierung bei einer Variable und startet ein 
//Skript, falls die Zeit der letzten zur aktuellen Aktualisierung kleiner gleich 
//einem Wert ist
class DBLClick extends IPSModule {
  public function Create() {
    parent::Create();
    $this->RegisterPropertyInteger('idSourceInstance', 0);
    $this->RegisterPropertyInteger('DBLClickTime', 1);
    $this->RegisterPropertyBoolean('CheckOneCLick', FALSE);
    
  }
  public function ApplyChanges() {
    parent::ApplyChanges();
    
    $this->RegisterPropertyInteger('DBLClickTime', 1);
    $this->RegisterPropertyInteger('idSourceInstance', 0); //Id der zu beobachtenden Variable
    $this->RegisterPropertyBoolean('CheckOneClick', FALSE);
    $DBLClickDetectId = $this->RegisterVariableBoolean('DBLClickDetect', 'DoppelKlickErkannt','', 1); //Boolean anlegen, der bei erkennung gesetzt wird 
    $lastUpdID = $this->RegisterVariableInteger('LASTUPD','last_updated','~UnixTimestamp',3);//Hilfsvariable anlegen
    
//Inhalt für Skript erzeugen, das bei Erkennung ausgeführt wird 
/*  $stringInhalt="<?\n IPS_LogMessage('DBLClick_Script','Starte User_Script.....................'); \n SetValueBoolean($DBLClickDetectId, FALSE); \n//Start your code here\n\n?>"; */
    //Skript anlegen
//    $scriptID = $this->RegisterScript('SCRIPT', 'DBLClickScript',$stringInhalt,2);
//    $presentId = $this->RegisterVariableInteger('PRESENT_SINCE', 'Anwesend seit', '~UnixTimestamp', 3);
//    $absentId = $this->RegisterVariableInteger('ABSENT_SINCE', 'Abwesend seit', '~UnixTimestamp', 3);
//    $nameId = $this->RegisterVariableString('NAME', 'Name_Device', '', 2);
//    IPS_SetIcon($this->GetIDForIdent('DBLClickDetect'), 'Motion');
//    IPS_SetIcon($this->GetIDForIdent('SCRIPT'), 'Keyboard');
    IPS_SetIcon($this->GetIDForIdent('LASTUPD'), 'Clock');
    
    if($this->ReadPropertyInteger('idSourceInstance')!=0){  
    	$this->RegisterEvent('OnVariableUpdate', 0, 'DBLC_Check($id)');
    }
  }
  protected function RegisterEvent($ident, $interval, $script) {
    $id = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
    if ($id && IPS_GetEvent($id)['EventType'] <> 1) {
      IPS_DeleteEvent($id);
      $id = 0;
    }
    if (!$id) {
      $id = IPS_CreateEvent(0);
      IPS_SetEventTrigger($id, 0, $this->ReadPropertyInteger('idSourceInstance')); //Bei Update von der gewählten Variable 
      IPS_SetEventActive($id, true);             //Ereignis aktivieren
      IPS_SetParent($id, $this->InstanceID);
      IPS_SetIdent($id, $ident);
    }
    IPS_SetName($id, $ident);
    IPS_SetHidden($id, true);
    IPS_SetEventScript($id, "\$id = \$_IPS['TARGET'];\n$script;");
    if (!IPS_EventExists($id)) throw new Exception("Ident with name $ident is used for wrong object type");
  }
 
  protected function Button_Decode($stringID) {
//Wert von "command" ermitteln
      $string=GetValueString($stringID);
//Test, ob Event ein kurzer Tastendruck war
      if(strstr(substr($string, -3), "111")===FALSE){ //Falls Update nicht durch einfachen Tastendruck verursacht
          return (-1);
      }
//Sender-Taste ermitteln (Tabelle und Tastennummer
      $source_table= substr($string,1,1);
      $source_button= substr($string, 2,1);
//Sender-Tabelle nach LCN in Buchstaben wandeln
      switch ($source_table){
        case "1": $source_table="A";          
          break;
        case "2": $source_table="B";
          break;
        case "3": $source_table="C";
          break;
        case "4": $source_table="D";
          break;
        default : return (-2);
            break;
      }
      $Taste=$source_table.$source_button;      
      return ($Taste);
  }
  
  protected function Script_Create($DBLClickDetectID,$lastUpdID,$source_taste,$instancethisID) {
      IPS_LogMessage('DBLClick_ScriptCreate',"Skript erstellen!");
      if($this->ReadPropertyBoolean('CheckOneCLick')){
          $string_OneClick="define('OneClick', 1);";
      }
      else{
          $string_OneClick="define('OneClick', 0);";
      } 
      
//Script Inhalt bei einerOne-Klick_Aktion
      $stringInhalt=
              "<?\n "
              . $string_OneClick
              . "\n IPS_LogMessage('DBLClick_Script'.'$source_taste','Starte User_Script.....................'); \n" 
              . " if(GetValueBoolean($DBLClickDetectID)){"
              . "   SetValueBoolean($DBLClickDetectID, FALSE); \n "
              . "   SetValueInteger($lastUpdID,GetValueInteger($lastUpdID)-20);\n"
              . "   }\n"
              . " else if(OneClick){"
              . "//Start your code here for OneClick\n\n"
              . "\n\n"
              . "   }"
              . "//Start your code here for DoubleClick\n\n?>";
      $scriptID= IPS_CreateScript(0);
      IPS_SetParent($scriptID, $instancethisID);
      IPS_SetName($scriptID, "Taste_".$source_taste);
      IPS_SetScriptContent($scriptID, $stringInhalt); 
      IPS_LogMessage('DBLClick_ScriptCreate',"Skript erstellt mit ID=".$scriptID);
      return($scriptID);
  }
  
  protected function Script_IDbyName($instThisID,$name) {
      $children=IPS_GetChildrenIDs($instThisID);
      
      foreach ($children as $child) {
          $child_Name= IPS_GetName($child);
          IPS_LogMessage('DBLClick_IDbyName',"Child =".$child_Name);
          $child_Name_8=substr($child_Name, 0, 8);
          IPS_LogMessage('DBLClick_IDbyName',"Child_8 =".$child_Name_8." zu Name=".$name);
          if(strstr($child_Name_8, $name)===FALSE){
             $scriptID=0; 
          }
          else{
              $scriptID=$child;
              break;
          }
      }
      IPS_LogMessage('DBLClick_IDbyName',"gefunden ID =".$scriptID."(".$child_Name.")");
      return($scriptID);
  }
  
  
  public function Check() {
//IPS_LogMessage('DBLClick',"Setze Semaphore");
    if(IPS_SemaphoreEnter('DBLClick', 1000)) {
//ID von "command" ermitteln
      $stringID=$this->ReadPropertyInteger('idSourceInstance');
//ID der aktuellen Instanz ermitteln   
      $inst_id=IPS_GetParent($stringID);	
      $inst_info= IPS_GetObject($inst_id);
      $inst_name=$inst_info['ObjectName'];
//Auswertung 
      IPS_LogMessage('DBLClick-'.$inst_name,"Starte Check.....................");
      $source_taste= $this->Button_Decode($stringID);
      if($source_taste==-1){
          IPS_LogMessage('DBLClick',"Update war kein Einfach-klick -> Exit");
          IPS_SemaphoreLeave('DBLClick');
          exit();
      }
      if($source_taste==-2){
          IPS_LogMessage('DBLClick',"Tastentabelle nicht erkannt -> Exit");
          IPS_SemaphoreLeave('DBLClick');
          exit ();
      }    
      IPS_LogMessage('DBLClick-'.$inst_name,"Taste =".$source_taste);
//Ermitteln ob doppelter Tastendruck in Zeit "DBLCLickTime" vorliegt
//ID der Bool-Variable für Doppelklick
      $DBLClickDetectID=$this->GetIDForIdent('DBLClickDetect');
      $instancethisID= IPS_GetParent($DBLClickDetectID);
//Eigenschaften der "command" Variable ermitteln 
      $stringInfo= IPS_GetVariable($stringID);
//Zeit des letzten Tastendrucks ermitteln
      $AktuelleZeit = $stringInfo['VariableUpdated'];//Zeitpunkt des aktuellen Updates
//Zeit des vorletzten Tastendrucks lesen
      $lastUpdID=$this->GetIDForIdent('LASTUPD');// ID für LastUpd suchen 
      $lastUpdValue= GetValueInteger($lastUpdID);// Wert für LastUpd lesen
//Eingestellte Grenze für Doppelklickerkennung lesen      
      $DBLClickTime= $this->ReadPropertyInteger('DBLClickTime');
      IPS_LogMessage('DBLClick-'.$inst_name,"Werte eingelesen");

//Skript für erkannte Taste ermitteln oder erstellen     
//Nur die ersten Zeichen prüfen, um erweiterte Bennenung zu zulassen
        $script_Name="Taste_".$source_taste;
        $scriptID= $this->Script_IDbyName($instancethisID, $script_Name); 
        
//Falls Actions-Skript noch nicht vorhanden
        if(!$scriptID){
//Script erstellen
            IPS_LogMessage('DBLClick-'.$inst_name,"Skript nicht gefunden!");
            $scriptID=$this->Script_Create($DBLClickDetectID,$lastUpdID,$source_taste,$instancethisID);   
        }
        else {
            IPS_LogMessage('DBLClick-'.$inst_name,"Skript ID=".$scriptID." gefunden");
            
        }  
//letzte Tastenbedienung speichern
      SetValueInteger($lastUpdID, $AktuelleZeit);
//Debugausgaben
      IPS_LogMessage('DBLClick-'.$inst_name,"Aktuelle Zeit =".$AktuelleZeit);
      IPS_LogMessage('DBLClick-'.$inst_name,"Letzer Click bei =".$lastUpdValue);
      IPS_LogMessage('DBLClick-'.$inst_name,"Differenz =".($AktuelleZeit-$lastUpdValue));
//Überprüfen ob Zeit zwischen vorletzter und letzter Bedienung kleiner Grenze ist
      if(($AktuelleZeit-$lastUpdValue)<=$DBLClickTime){ 
	SetValueBoolean($DBLClickDetectID, true);
        IPS_LogMessage('DBLClick',"Doppelklick-Aktion starten");
        IPS_RunScript($scriptID);
      }
      else{
// Prüfen, ob ein Aktion bei Einfachklick gewünscht ist (bei Lichtschaltern)
	if($this->ReadPropertyBoolean('CheckOneCLick')){
            IPS_LogMessage('DBLClick',"Einfachklick-Aktion starten");
            IPS_RunScript($scriptID);
        }
        else{
            IPS_LogMessage('DBLClick-'.$inst_name,"Doppelklick nicht erkannt");   
        }
        
      }
      IPS_SemaphoreLeave('DBLClick');
     } 
     else {
      IPS_LogMessage('DBLClick', 'Semaphore Timeout');
    }
   }
} 
?>
