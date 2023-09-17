<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use GuzzleHttp\Client;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;
use Carbon\CarbonPeriod;


class GetDataController extends Controller
{

    public function getDataFromWebPage(Request $request)
    {
        $requestContent = [
        'headers' => [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ],
        'verify' => false
        ];
        $response = null;
        try {
            $client = new \GuzzleHttp\Client();;
            // return tranasction data from external api
            $apiRequest = $client->request('GET', 'https://api.etherscan.io/api?module=account&action=txlist&address='.$request->requiredAddress.'&startblock='.$request->startBlock.'&endblock='.$request->endBlock.'&page='.$request->page.'&offset='.$request->offset.'&sort='.$request->sort.'&apikey='.env('APITOKEN_KEY'), $requestContent);

            $response = json_decode($apiRequest->getBody());

        } catch (RequestException $re) {
            Log::error('Exception:'. $re->getMessage());
        }
        // convert wei value to ETH
        $temp = $response->result;
        for ($i = 0; $i<count($response->result); $i++)
        {
            $temp[$i]->value= $temp[$i]->value/1000000000000000000;

        }
        $response->result = $temp;

        return $response;

    }
    public function calculateEthBalanceInPast(Request $request)
    {
        //date time to unix format
        $ad = strtotime($request->requiredDate.' 00:00:00');

        //convert to y m d
        $date2 = Carbon::createFromFormat('Y-m-d', $request->requiredDate)->format('Y-m-d');

        //get today date
        $mytime = Carbon::now()->format('Y-m-d');

        //list dates from today to date in past
        $period = CarbonPeriod::create($date2, $mytime);
        // Iterate over the period
        $temp = [];
        foreach ($period as $date) {
            $temp[] = $date->format('Y-m-d');
        }
        $allDates = array_reverse($temp);
        // working with internal tranasction add ETH

        $internalDatesEth = $this->getDatesWithEthValue($request->requiredAddress, $ad, 'txlistinternal');

        // working with normal tranasction add ETH

        $normalDatesEth = $this->getDatesWithEthValue($request->requiredAddress, $ad, 'txlist');

        //get ether balance for now

        $requestContent = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'verify' => false
        ];
        $client = new \GuzzleHttp\Client();;
        $apiRequest = $client->request('GET', 'https://api.etherscan.io/api?module=account&action=balance&address='.$request->requiredAddress.'&tag=latest&apikey='.env('APITOKEN_KEY'), $requestContent);
        $response = json_decode($apiRequest->getBody());
        $startBalance = $response->result/1000000000000000000;


        //check if in one day don't have tranasction, if true set that day eth value to 0
        $clearedArrayInternal = $this->checkIfOneDayZero($allDates, $internalDatesEth);
        $clearedArrayNormal = $this->checkIfOneDayZero($allDates, $normalDatesEth);

        // calculate balance for every day, if day in plus or if day in minus
        $daysBalance = array();

        foreach($allDates as $counter => $oneDate)
        {
            $valueOfEth = $clearedArrayInternal[$counter]['ethValue']+(-$clearedArrayNormal[$counter]['ethValue']);
            $daysBalance[$counter]= (['date'=>$allDates[$counter] , 'ethValue' => $valueOfEth]);
        }
        //we move from today to a day in the past
        //if the previous day has a negative value, it means that we were in the minus yesterday and we add that value to today's and so on for each day
        // if the previous day has a positive value of eth, it means that we are in the plus, and we subtract that value from today
        $result = 0;
        for ($i = 0; $i<count($daysBalance);$i++)
        {
            if($daysBalance[$i]['ethValue']<0)
            {
                $result = $startBalance+abs($daysBalance[$i]['ethValue']);
            }else if ($daysBalance[$i]['ethValue']>0){
                $result = $startBalance-$daysBalance[$i]['ethValue'];
            }

        }
        return response()->json(['result' => $result]);

    }
    public function checkIfOneDayZero($allDates, $arrayForCheck)
    {
        //get only dates from array with key: date value:ethValue
        $checkArray = array();
        for ($i = 0 ; $i < count($arrayForCheck); $i++)
        {
            $checkArray[] = $arrayForCheck[$i]['date'];
        }
        //check if some date misssing from check array, that means that in some day we don t have any tranasction
        $arrdif = null;
        if(count($allDates)>count($checkArray))
        {
            $arrdif = array_diff($allDates, $checkArray);
        }else {
            $arrdif = array_diff($checkArray, $allDates);
        }
        //if there day with 0 tranasction, we set missing date with value 0 for ethValue
        if ($arrdif != null){
            $keys = array_keys($arrdif);
            foreach($keys as $oneItem)
            {
                $inserted = (['date'=>$arrdif[$oneItem] , 'ethValue' => 0]);
                array_splice( $arrayForCheck, $oneItem, 0, [$inserted] );
            }

        }
        return $arrayForCheck;
    }

    public function getDatesWithEthValue($address, $timeInPast, $typeOfTransaction)
    {
        $client = new \GuzzleHttp\Client();;
        $requestContent = [
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
            'verify' => false
        ];
        //returning the specified block from which it moves according to the passed date
        $apiRequest = $client->request('GET', 'https://api.etherscan.io/api?module=block&action=getblocknobytime&timestamp='.$timeInPast.'&closest=before&apikey=V2E7AJQUK9IGQPY6XJD4KYU9TJ3F8KH3XY', $requestContent);
        $response = json_decode($apiRequest->getBody());
        $startBlockFromPast = $response->result;

        // return all transaction(internal/normal) started with specific block
        $apiRequest = $client->request('GET', 'https://api.etherscan.io/api?module=account&action='.$typeOfTransaction.'&address='.$address.'&startblock='.$startBlockFromPast.'&endblock=&page=&offset=&sort=desc&apikey='.env('APITOKEN_KEY'), $requestContent);
        $response = json_decode($apiRequest->getBody());
        $arrayOfInternalTrans = $response->result;

        // we move from the most recent transactions to the transactions in the past (starting block) and sort the eth value by dates
        $result= array();
        $valueOfEthPerDay= 0;
        $changeWindow = 0;
        for ($i = 0; $i<count($arrayOfInternalTrans);$i++){
            $currentDate = Carbon::createFromTimestamp($arrayOfInternalTrans[$i]->timeStamp)->toDateString();
            if(empty($result) == true)
            {
                $result[0] = ['date'=>$currentDate , 'ethValue' => $arrayOfInternalTrans[$i]->value/1000000000000000000];
            }else {

                if ($currentDate!=$result[$changeWindow]['date']){
                    $valueOfEthPerDay= 0;
                    $valueOfEthPerDay+=$arrayOfInternalTrans[$i]->value/1000000000000000000;
                    $result[1+$changeWindow] = ['date'=>$currentDate , 'ethValue' => $valueOfEthPerDay];
                    $changeWindow+=1;

                }else if ($currentDate == $result[$changeWindow]['date']){
                    $result[$changeWindow]['ethValue']+=$arrayOfInternalTrans[$i]->value/1000000000000000000;
                }
            }
        }
        return $result;
    }


}
