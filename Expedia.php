<?php

include(sfConfig::get('sf_lib_dir').'/adodb5/adodb.inc.php');
include(sfConfig::get('sf_lib_dir').'/adodb5/tohtml.inc.php');

class Expedia{
    public static $url      = 'https://simulator.expediaquickconnect.com/connect/br';
    public static $user     = 'anyuser';
    public static $password = 'ECLPASS';
    public static function requestExpedia(){
        
        $url = 'https://simulator.expediaquickconnect.com/connect/br';

        $xml_data = '<?xml version="1.0" encoding="UTF-8"?> <BookingRetrievalRQ xmlns="http://www.expediaconnect.com/EQC/BR/2014/01" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><Authentication username="'.self::$user.'" password="'.self::$password.'"/><Hotel id="111" /> </BookingRetrievalRQ>';
        $xml = Expedia::create_request(self::$url,$xml_data);
        $fp = fopen(sfConfig::get('sf_app_dir').DIRECTORY_SEPARATOR.'xmls'.DIRECTORY_SEPARATOR.microtime(true).'.xml',"a");
        fwrite($fp,$xml . PHP_EOL);
        fclose($fp);
        
        $array = self::expediaXML($xml);
        $conversion = self::conversion($array);
        //print_r($conversion);
        foreach($conversion as $booking){

            self::processType($booking);
        }
        
    }
    public static function reprocess($booking){
        $booking->costoTotal = self::getPrice($booking);

        $hotel    = $booking->Hotel;
        $id       = $booking->id;
        $type     = $booking->type;

        $sp_param = self::getParamSP($booking);
        $sp       = self::toSP('spReservacionesExpedia',$sp_param);

        if($sp){
            $resultado  = $sp[0];//Resultado
            $mensaje    = $sp[1];//Mensaje
            $numConfirm = $sp[2];//Numero de Confirmacion
            $ret = false;
            switch($resultado){
                case 0:
                    $reservado = self::setReserved($hotel, $id, $numConfirm, $type);
                    //print_r($reservado);
                    if(isset($reservado['status'])){
                        if($reservado['status'] == '1'){
                            $ret = true;
                            echo 'Reservado';
                        }else{

                            $std = new stdClass();
                            
                            $std->status = '1';
                            $std->error  = $reservado['error'];
                            $std->ref    = $reservado['ref'];
                            
                            self::error($booking->id,$booking,$std);
                        }
                    }else{
                        $std = new stdClass();
                        
                        $std->status = '2';
                        
                        self::error($booking->id,$booking,$std);
                    }
                break;
                default:
                    $std = new stdClass();
                    
                    $std->status = '3';
                    $std->resultado  = $resultado;
                    $std->mensaje    = $mensaje;
                    $std->numConfirm = $numConfirm;
                    
                    self::error($booking->id, $booking, $std);
                break;
            }
        }else{
            $ret = false;
            $std = new stdClass();
            $std->status = '5';
            self::error($booking->id, $booking, $std);
        }
        
        return $ret;
    }
    public static function processType($booking){
        switch($booking->type){
            case'Cancel':
                echo 'Cancelando Reservacion: '.$booking->id.'<br>';

                //self::cancel();
            break;
            case'Modify':
                echo 'Reservacion Modificada: '.$booking->id.'<br>';
                if(!isset($booking->conversion->error)){
                
                self::reprocess($booking);
                    
                }else{
                    $std = $booking->conversion->error;
                    $std->status = '4';
                    self::error($booking->id, $booking, $std);
                }
            break;
            case'Book':
                echo 'Reservacion: '.$booking->id.'<br>';
                if(!isset($booking->conversion->error)){
                
                self::reprocess($booking);
                    
                }else{
                    $std = $booking->conversion->error;
                    $std->status = '4';
                    self::error($booking->id, $booking, $std);
                }
                
            break;
        }
    }
    public static function error($booking_id, $booking, $error){
        $data = json_encode($booking);
        $error = json_encode($error);

        $new = new Criteria();
        $new->add(ReservacionfallidaPeer::BOOKING_ID,$booking_id);
        $new->setLimit(1);

        if(ReservacionfallidaPeer::doCount($new) <= 0){
            $fallida = new Reservacionfallida();
            $fallida->setNew(true);
            $fallida->setBookingId($booking_id);
            $fallida->setData($data);
            $fallida->setDate(time());
            $fallida->setError($error);
            $fallida->save();
        }
    }
    public static function cancel($booking){

    }
    public static function json_decode($json){
        $json = html_entity_decode($json);
        $d = '';
        if(get_magic_quotes_gpc()){
            $d = stripslashes($json);
        }else{
            $d = $json;
        }

        $obj = json_decode($json);
        return $obj;
    }
    public static function create_request($url,$xml){
        $ch = curl_init();
        $xml_data = $xml;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: text/xml'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, "$xml_data");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        $output = curl_exec($ch);

        return $output;
    }
    public static function setReserved($hotel, $bookingId, $confirmNumber,$type){
        $time = time();
        $confirmTime = date('Y',$time).'-'.date('m',$time).'-'.date('d',$time).'T'.date('G',$time).':'.date('i',$time).':'.date('s',$time).'Z';
        $xml = '<BookingConfirmRQ xmlns="http://www.expediaconnect.com/EQC/BC/2007/09"><Authentication username="'.self::$user.'" password="'.self::$password.'"/> <Hotel id="'.$hotel.'"/> <BookingConfirmNumbers><BookingConfirmNumber bookingID="'.$bookingId.'" bookingType="'.$type.'" confirmNumber="'.$confirmNumber.'" confirmTime="'.$confirmTime.'"/> </BookingConfirmNumbers></BookingConfirmRQ>';
        $url = 'https://simulator.expediaquickconnect.com/connect/bc';
        $request = self::create_request($url,$xml);
        $obj = self::XMLtoObj($request);
        //print_r($obj);
        if(isset($obj->Success)){
           return array('status'=>'1');
        }else{
            $error = explode('.',$obj->Error);
            $ref = array_pop($error);
            $error = implode('.',$error);
            return array('status' => '0', 'error' => $error, 'ref' => $ref);
        }
    }
    public static function XMLtoObj($xml){
        $class = new XML2Array($xml);
        $obj = $class->getObject();
        return $obj;
    }
    public static function getPrice($obj){
        //print_r($obj);
        $sumatoria = $obj->Room->Total;
        
        $adult = (int)$obj->conversion->guest->adult;
        $teen  = (int)$obj->conversion->guest->teen;

        $cost_adult = (int)sfConfig::get('app_expedia_costadult');
        $cost_teen  = (int)sfConfig::get('app_expedia_costteen');


        $adult = $adult * $cost_adult;
        $teen  = $teen  * $cost_teen;

        switch($obj->conversion->mealplan){
            case'EP':
               return $sumatoria;
            break;
            case'IA':
                return $sumatoria-( $adult +$teen);
            break;
        }
    }
    public static function stringToObject($xml){
        $array = self::expediaXML($xml);
        return $array;
    }
    public static function connect($host,$user,$pass,$db){
            $db = ADONewConnection('odbc_mssql');
            $dsn = "Driver={SQL Server};Server=".$host.";Database=".$db.";";
            $db->debug = true;
            $db->Connect($dsn,$user,$pass);
            return $db;
    }
    public static function typeReservation($type){
        switch($type){
            case 'Cancel':
                $return = 'C';
            break;
            case 'Modify':
                $return = 'M';
            break;
            case 'Book':
                $return = 'N';
            break;
        }
        return $return;
    }
    public static function expediaXML($xmlString){

        $array = array();
        $xml = new XML2Array($xmlString);
        $obj = $xml->getObject();
        foreach($obj->Bookings->children() as $Booking){
            $book = new stdClass();
            $attr = $Booking->attributes();
            $date = format::date($attr->createDateTime,'d-m-Y G:i:s');

            $Hotel = $Booking->Hotel;

            $RoomStay = $Booking->RoomStay;
            $Room_attr = $RoomStay->attributes();
            $StayDate = $RoomStay->StayDate->attributes();
            $Guest = $RoomStay->GuestCount->attributes();

            $cost = $RoomStay->Total->attributes();

           


            $PrimaryGuest = $Booking->PrimaryGuest;

                //********************************************************************
            $book->id     = ''.$attr->id;
            $book->type   = ''.$attr->type;
            $book->fDate  = ''.$date;
            $book->date   = ''.$attr->createDateTime;
            $book->source = ''.$attr->source;
            //$book->sourID = Expedia::getSource($book->source);
            $book->Hotel  = ''.$Hotel->attributes()->id;

            $book->Room   = new stdClass();
            $book->Room->arrival   = ''.$StayDate->arrival;
            $book->Room->departure = ''.$StayDate->departure;
            $book->Room->type      = ''.$Room_attr->roomTypeID;
            $book->Room->plan      = ''.$Room_attr->ratePlanID;
            $book->Room->RatePlan  = '';

            $book->Room->Guest     = new stdClass();
            $book->Room->Guest->adultos = ''.$Guest->adult;
            $book->Room->Guest->child   = ''.$Guest->child;
            $book->Room->Guest->children = array();
            foreach($RoomStay->GuestCount->children() as $child){
                $book->Room->Guest->children[] = ''.$child->attributes()->age;
            }
            $book->Room->Cost = array();
            $book->Room->Days = 0;
            $avg = 0;
            foreach($RoomStay->PerDayRates->children() as $day){
                $d = new stdClass();
                $d_attr = $day->attributes();
                $d->stay  = ''.$d_attr->stayDate;
                $d->base  = ''.$d_attr->baseRate;
                $d->promo = ''.$d_attr->promoName;
                $d->extra = ''.$d_attr->extraPersonFees;
                $d->hotel = ''.$d_attr->hotelServiceFees;
                $avg = $avg+$d->base;
                $book->Room->Cost[] = $d;
                $book->Room->Days++;
            }
            $book->Room->AvgDays  = ''.$avg/$book->Room->Days;
            $book->Room->Total    = ''.$cost->amountAfterTaxes;
            $book->Room->Avg      = ''.$book->Room->Total/$book->Room->Days;
            $book->Room->Taxes    = ''.$cost->amountOfTaxes;
            $book->Room->currency = ''.$cost->currency;


            $client = $PrimaryGuest->Name->attributes();
            $phone  = $PrimaryGuest->Phone->attributes();


            $book->Client = new stdClass();
            $book->Client->firstname  = ''.$client->givenName;
            $book->Client->middlename = ''.$client->middleName;
            $book->Client->lastname   = ''.$client->surname;


            if(isset($Booking->RoomStay->PaymentCard)){
                $CardPayment = $Booking->RoomStay->PaymentCard;
                $CardHolder  = $CardPayment->CardHolder;
                $CardAttr    = $CardPayment->attributes();
                $CardHoldAt  = $CardHolder->attributes();
                $CardType    = $CardAttr->cardCode;
                $CardNumber  = $CardAttr->cardNumber;
                $CardExpire  = $CardAttr->expireDate;

                $address     = ''.$CardHoldAt->address;
                $name        = ''.$CardHoldAt->name;
                $state       = trim(''.$CardHoldAt->stateProv);
                $postal      = ''.$CardHoldAt->postalCode;
                $country     = trim(''.$CardHoldAt->country);
                $city        = ''.$CardHoldAt->city;

                $book->Client->cardname  = $name;
                $book->Client->address   = $address;
                $book->Client->zipcode   = $postal;
                $book->Client->city      = $city;
                $book->Client->state     = $state;
                $book->Client->country   = $country;


                $book->Client->countryID = self::getCountry($country);
                $book->Client->stateID   = self::getState($state);
            }

            $book->Client->countryCode  = ''.$phone->countryCode;
            $book->Client->cityAreaCode = ''.$phone->cityAreaCode;
            $book->Client->number       = ''.$phone->number;
            $book->Client->compleNumber = $book->Client->countryCode.'-'.$book->Client->cityAreaCode.'-'.$book->Client->number;
            $book->Client->extension    = ''.$phone->extension;
            $book->Client->Email        = ''.$PrimaryGuest->Email;


            $book->request = array();

            if($Booking->RewardProgram){
                $rewards = $Booking->RewardProgram->attributes();
                
                $book->Rewards = new stdClass();
                $book->Rewards->code  = ''.$rewards->code;
                $book->Rewards->number = ''.$rewards->number;
            }

            foreach($Booking->SpecialRequest as $special){
                $s = new stdClass();
                $s->request = (string)$special;
                $s->code    = (string)$special->attributes()->code;
                $book->request[] = $s;
            }
            $array[] = $book;
        }
        return $array;
    }
    public static function getDistintivo($nombre){
        $criteria = new Criteria();
        $criteria->add(DistintivoPeer::NOMBRE,$nombre,Criteria::LIKE);
        $criteria->setLimit(1);

        $distintivo = DistintivoPeer::doSelectOne($criteria);

        if($distintivo){
            $std = new stdClass();
            $std->id = $distintivo->getId();
            $std->nombre = $distintivo->getNombre();
            $std->codigo = $distintivo->getCodigo();

            return $std;
        }else{
            return false;
        }
    }
    public static function conversionOne($booking){
            $prePMS = new stdClass();
            $error  = new stdClass();

            $conversion = new stdClass();
            
            $adult = 0;
            $teen  = 0;
            $child = 0;

            $plan   = self::getPlan($booking->Room->plan);
            $room   = self::getRoom($booking->Room->type);
            $source = self::getSource($booking->source);

            $adult = $adult+$booking->Room->Guest->adultos;

            foreach($booking->Room->Guest->children as $ch){
                switch(true){
                    case($ch >= 13):
                        $adult++;
                    break;
                    case($ch<13 && $ch >= 6):
                        $teen++;
                    break;
                    case($ch<6):
                        $child++;
                    break;
                }
                if($ch > 13){
                    $adult++;
                }
            }

            if($source == False){
                $error->source[0] = 'No Source Founded';
                $source = '000';
            }
            if($room == False){
                $error->room[0] = 'No Room Founded';
                $room = '000';
            }
            if($plan == False){
                $error->plan[0] = 'No RatePlan Founded';
                $plan = '000';
            }

            $prePMS->rateplan = $plan;
            $prePMS->room     = $room;
            $prePMS->source   = $source;


            $criteria = new Criteria();
            $criteria->add(ConversionPeer::ROOM_ID,$room);
            $criteria->add(ConversionPeer::RATEPLAN_ID,$plan);

            $conver_count = ConversionPeer::doCount($criteria);
            if($conver_count > 0){
               $conver = ConversionPeer::doSelectOne($criteria);

                if($source->type == 0){
                    $codigomercado = sfConfig::get('app_expedia_codigodemercadoregular');
                    $codigo = $conver->getCodigoTarifaRegular();
                }else{
                    $codigomercado = sfConfig::get('app_expedia_codigodemercadocollect');
                    $codigo = $conver->getCodigoTarifaCollect();
                }

                $ro = RoomPeer::retrieveByPK($prePMS->room);
                $ra = RateplanPeer::retrieveByPK($prePMS->rateplan);

                $pais = (int)$booking->Client->countryCode;

                $conversion->sourcetype = $source->type;
                $conversion->rate       = $ra->getName();

                $conversion->room       = $ro->getPmsId();

                $conversion->room_id    = $prePMS->room;
                $conversion->rate_id    = $prePMS->rateplan;

                $conversion->codigo     = $codigo;
                $conversion->mensaje    = $conver->getMensaje(); 
                $conversion->distintivo = $conver->getDistintivo();
                $conversion->distinID   = $conver->getDistintivoId();
                $conversion->arrival    = $booking->Room->arrival;
                $conversion->departure  = $booking->Room->departure;
                $conversion->mercado    = $codigomercado;

                $conversion->companyID  = $prePMS->source->companyPMS;

                $conversion->mealplan = self::getMealplan($conversion->rate_id);

                $guest = new stdClass();
                $guest->adult = $adult;
                $guest->teen  = $teen;
                $guest->child = $child;

                $conversion->guest = $guest;


                $booking->conversion = $conversion;
            }else{
                $error->conversion[0] = 'No Conversion Founded';
                $conversion->error = $error;

                $booking->conversion = $conversion;
            }
            $booking->PrePMS = $prePMS;
           
            return $booking;
    }
    public static function conversion($array){
        foreach($array as $index => $booking){
            
            $booking = self::conversionOne($booking);
            $array[$index] = $booking;
        }
        
        return $array;
    }
    public static function getCountry($code){
       $code = strtoupper($code);

       $criteria = new Criteria();
       $criteria->add(CountryPeer::CODE,$code);
       $c = CountryPeer::doSelectOne($criteria);
       
       if($c){
        return $c->getId();
       }else{
        return 0;
       }
    }
    public static function getState($code){
        $code = strtolower($code);
        $criteria = new Criteria();
        $criteria->add(EstadosPeer::CODE,$code);
        $criteria->setLimit(1);

        $c = EstadosPeer::doSelectOne($criteria);
        if($c){
            return $c->getId();
        }else{
            return 0;
        }
    }
    public static function getConversion($plan,$room){
        if($plan && $room){
            $criteria = new Criteria();
            $criteria->add(ConversionPeer::RATEPLAN_ID,$plan->getId());
            $criteria->add(ConversionPeer::ROOM_ID,$room->getId());
            $criteria->setLimit(1);
            return ConversionPeer::doSelectOne($criteria);
        }else{
            return false;
        }
    }
    public static function getSource($name){
        $criteria = new Criteria();
        $criteria->add(SourcePeer::BOOKING,$name);
        $count = SourcePeer::doCount($criteria);
        $r = false;
        if($count > 0){
            $so = SourcePeer::doSelectOne($criteria);
            $source = new stdClass();
            $source->booking  = $so->getBooking();
            $source->company  = $so->getCompaniaId();
            $source->agency   = $so->getAgenciaId();
            $source->type     = $so->getType();
            $comp             = self::getCompania($so->getCompaniaId());
            
            if($comp){
                $source->companyPMS = $comp->getPmsId();
            }else{
                $source->companyPMS = 0;
            }
            

            $r  = $source;
        }
        return $r;
    }
    public static function getCompania($id){
        $obj = CompaniaPeer::retrieveByPK($id);
        return $obj;
    }
    public static function getAgencia($id){
        $agencia = AgenciaPeer::retrieveByPK($id);
        return $agencia->getIdPms();
    }
    public static function getRoom($value){
        $room = $value;
        $criteria = new Criteria();
        $criteria->add(RoomPeer::EXPEDIA_ID,$room);
        $count = RoomPeer::doCount($criteria);
        $r = false;
        if($count > 0){
            $ro = RoomPeer::doSelectOne($criteria);
            $r = $ro->getId();
        }
        return $r;
    }
    public static function getPlan($value){
            $rateplan = $value;
            $criteria = new Criteria();
            $criteria->add(RateplanExpediaPeer::EXPEDIA_ID,$rateplan);
            $count = RateplanExpediaPeer::doCount($criteria);
            $r = false;
            if($count > 0){
                $rp = RateplanExpediaPeer::doSelectOne($criteria);

                $r = $rp->getRateplanId();
            }
            return $r;
    }
    public static function roomExpediatoPMS($expedia_id){
        $criteria = new Criteria();
        $criteria->add(RoomPeer::EXPEDIA_ID,$expedia_id);
        $criteria->setLimit(1);
        return RoomPeer::doSelectOne($criteria);
    }
    public static function planExpediatoPMS($expedia_id){
        $criteria = new Criteria();
        $criteria->addJoin(RateplanExpediaPeer::RATEPLAN_ID,RateplanPeer::ID);
        $criteria->add(RateplanExpediaPeer::EXPEDIA_ID,$expedia_id);
        $criteria->setLimit(1);
        $rateplan = RateplanPeer::doSelectOne($criteria);
        return $rateplan;
    }
    public static function getResort(){
        return sfConfig::get('app_expedia_resort');
    }
    public static function getMealplan($rateplan){
        $rate = RateplanPeer::retrieveByPK($rateplan);
        $mealplan = $rate->getMealplan();
        switch ($mealplan) {
            case '1':
                return 'AI';
            break;
            
            case '0':
                return 'EP';
            break;
        }
    }
    public static function getComentario($obj){
        //return $obj->conversion->mensaje;
        $conversion = $obj->conversion;
        $plan    = $conversion->mealplan;
        $x       = $conversion->guest->adult;
        $y       = $conversion->guest->teen;
        $z       = $conversion->guest->child;
        $mensaje = $conversion->mensaje;
        return $plan.'//'.$x.'.'.$y.'.'.$z.'//'.trim($mensaje);
    }
    public static function toSP($sp,$param){

        $db     = sqlServer::getConfig();
        
        $return = "DECLARE @return_value int,@Result bit,@ResultMessage varchar(50),@NumConfirm int;";
        $dec = "SELECT @Result as N'@Result',@ResultMessage as N'@ResultMessage',@NumConfirm as N'@NumConfirm';SELECT 'Return Value' = @return_value;";
        
        $sql = $return.'EXEC    @return_value = [dbo].['.$sp.'] '.$param.';'.$dec;
        echo '<br>'.$sql.'<br>';
        $fields = '';
        try{
            $rs = $db->Execute($sql,true);
            if($rs){
                $fields = $rs->fields;
            }else{
                $fields = false;
            }
        }catch(Exception $e){
            echo 'ERROR:'.$e;
            $fields = false;
        }
        
        return $fields;
    }
    public static function getParamAllSP($array){
        $x = array();
        foreach($array as $obj){
            $x[] = sqlServer::createParam($obj);
        }
        return $x;
    }
    public static function getParamSP($obj){
        return sqlServer::createParam($obj);
    }
}
class sqlServer{
    public static function getConfig(){
        $db       = ADONewConnection('odbc_mssql');
        $server   = sfConfig::get('app_expedia_host');
        $database = sfConfig::get('app_expedia_db');
        $user     = sfConfig::get('app_expedia_user');
        $pass     = sfConfig::get('app_expedia_pass');
        
        $dsn = "Driver={SQL Server};Server=".$server.";Database=".$database.";";
        $db->debug=false;
        $db->Connect($dsn,$user,$pass);

        return $db;
    }

    public static function createParam($obj){

            if(!isset($obj->conversion->error)){
                $countryID = (isset($obj->Client->countryID))?$obj->Client->countryID:0;
                $stateID   = (isset($obj->Client->stateID))?$obj->Client->stateID:0;
                $address   = (isset($obj->Client->address))?$obj->Client->address:'';
                $city      = (isset($obj->Client->city))?$obj->Client->city:'';
                $zipcode   = (isset($obj->Client->zipcode))?$obj->Client->zipcode:'';

                $total             = ($obj->Room->Total/$obj->Room->Days);
                $adult             = $obj->Room->Guest->adultos;
                $child             = $obj->Room->Guest->child;
                $arrival           = self::getDate($obj->Room->arrival);
                $departure         = self::getDate($obj->Room->departure);
                $rate              = $obj->Room->AvgDays;
                $rateID            = $obj->conversion->rate_id;
                $comments          = Expedia::getComentario($obj);
                $room_type         = $obj->conversion->room;//tbaTiposdeHabitacion
                $resort            = Expedia::getResort();//tbaResorts
                $agency            = Expedia::getAgencia($obj->PrePMS->source->agency);
                $market            = $obj->conversion->mercado;
                $booking           = $obj->id;
                $type_reservation  = self::typeReservation($obj->type);
                $guest_firstname   = $obj->Client->firstname;
                $guest_lastName    = $obj->Client->lastname;
                $guest_account     = "NULL";
                $guest_phone       = $obj->Client->compleNumber;
                $guest_email       = $obj->Client->Email;
                $guest_address     = $address;// Address
                $guest_city        = $city;// City
                $guest_zip         = $zipcode;// Zip
                $state             = $stateID;// State
                $country           = $countryID;// Country
                $compania          = $obj->conversion->companyID;
                $distintivo        = $obj->conversion->distinID;

                $param['@NumAdults']       = self::format('int',$adult);
                $param['@NumChildren']     = self::format('int',$child);
                $param['@ArrivalDate']     = self::format('date',$arrival);
                $param['@DepartureDate']   = self::format('date',$departure);
                $param['@NightlyRate']     = self::format('float',$rate);
                $param['@nightlyRateID']   = self::format('id',$rateID);
                $param['@comments']        = self::format('string',$comments);
                $param['@idRoomType']      = self::format('id',$room_type);
                $param['@idResort']        = self::format('id',$resort);
                $param['@idAgency']        = self::format('id',$agency);
                $param['@idMarketCode']    = self::format('id',$market);
                $param['@bookingID']       = self::format('id',$booking);
                $param['@TypeReservation'] = self::format('string',$type_reservation);
                $param['@guestFirstName']  = self::format('string',$guest_firstname);
                $param['@guestLastName']   = self::format('string',$guest_lastName);
                $param['@guestAccount']    = self::format('string','');
                $param['@guestPhone']      = self::format('string',$guest_phone);
                $param['@guestEmail']      = self::format('string',$guest_email);
                $param['@guestAddress']    = self::format('string',$guest_address);
                $param['@guestCity']       = self::format('string',$guest_city);
                $param['@guestZip']        = self::format('string',$guest_zip);
                $param['@idState']         = self::format('id',$state);
                $param['@idCountry']       = self::format('id',$country);
                $param['@idCompania']      = self::format('id',$compania);
                $param['@idDistintivos']   = self::format('id',$distintivo);
                $param['@Result']          = self::format('output','@Result');
                $param['@ResultMessage']   = self::format('output','@ResultMessage');
                $param['@NumConfirm']      = self::format('output','@NumConfirm');
                $param                     = self::formatCreate($param);
            }else{
                $param = '';
            }
            return $param;
        }
        public static function getDate($date){
            list($year,$month,$day) = explode('-',$date);
            return $day.'/'.$month.'/'.$year;
        }
        public static function typeReservation($type){
            switch($type){
                case 'Cancel':
                    $return = 'C';
                break;
                case 'Modify':
                    $return = 'M';
                break;
                case 'Book':
                    $return = 'N';
                break;
            }
            return $return;
        }
        public static function format($name,$value){
            switch($name){
                case 'int':
                    if(empty($value)){
                        return 0;
                    }else{
                        return $value;
                    }
                break;
                case 'date':
                    return "N'".$value."'";
                break;
                case 'float':
                case 'id':
                    return $value;
                break;
                case 'string':
                if(is_array($value)){
                    $x = '';
                    foreach($value as $v){
                        $x = $v->request."\n";
                    }
                    return "N'".$x."'";
                }else if(!empty($value)){
                    return "N'".$value."'";
                }else{
                    return 'NULL';
                }
                break;
                case 'output':
                    return $value.' OUTPUT';
                break;
            }
        }
        public static function formatCreate($array){
            $count = count($array);
            $x = 0;
            $ret = '';
            foreach($array as $n=>$v){
                if(($count-1) == $x){
                    $ret .= $n.' = '.$v.'';
                }else{
                    $ret .= $n.' = '.$v.',';
                }
                $x++;
            }
            return $ret;
        }
        public static function declareReturn($return){
            $ret = '';
            $val = array();
            foreach($return as $v=>$r){
                if($v == '@return_value'){
                    $ret = 'SELECT "Return Value" = @return_value';
                }else{
                    $val[] = $v.' as "'.$v.'"';
                }
            }
            $v = implode(',',$val);
            return 'SELECT '.$v.';'.$ret;
        }
        public static function createReturn($return){
            $x = array();
            foreach($return as $v=>$r){
                $x[] = $v.' '.$r;
            }
            return 'DECLARE '.implode(',',$x).';';
        }
}
?>
