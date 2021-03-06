<?php
class ComKafka{

    CONST PRODUCER_PARTITION_MODE_CONSISTENT = -1;
    CONST PRODUCER_PARTITION_MODE_RANDOM     = -2;
    CONST PRODUCER_REQUEST_ACK               =  1;//-1所有replicas同步完成，0不发送响应，1only leader

    CONST CONSUMER_MESSAGE_MAX_BYTES         = 1048576;
    CONST OPERATE_TIMEOUT_MS  = 1000;

    private $offset_path = "/Users/jinglinhu/Desktop/kafka-offset-data/";

    private $topic_name;
    private $all_partitions;

    private $producer;
    private $producer_topic;

    private $consumer;
    private $queue;
    private $consumer_topic;

    function __construct($topic_name,$identity,$host_name="kafka"){

        $conf = YII::app()->params[$host_name];

        if($conf == '' || $topic_name  == '' || $identity  == ''){
            echo "error : no init param";
            exit;
        }

        if(!in_array($identity,array('producer','consumer'))){
            echo "error : wrong identity";
            exit;
        }
        $this->topic_name = $topic_name;

        //初始化全局配置
        $rd_conf = new RdKafka\Conf();
        $rd_conf -> set('metadata.broker.list',$conf['host']);
        #$rd_conf -> set('socket.keepalive.enable',true);
        #$rd_conf -> set('log_level',LOG_DEBUG);//Logging level (syslog(3) levels)
        //print_r($rd_conf->dump());exit;
        switch ($identity) {
            case 'producer':
                $rd_conf -> set('compression.codec','none');//Compression codec to use for compressing message sets: none, gzip or snappy;default none
                $this->producer = new RdKafka\Producer($rd_conf);
                break;

            case 'consumer':

                $rd_conf -> set('fetch.message.max.bytes',self::CONSUMER_MESSAGE_MAX_BYTES);
                $this->consumer = new RdKafka\Consumer($rd_conf);

                $rd_topic_conf = new RdKafka\TopicConf();

                $back=debug_backtrace();
                $back=$back[1];
                $group = $this->topic_name."_".$back['class']."_".$back['function'];

                $rd_topic_conf -> set('group.id',$group);
                $rd_topic_conf -> set('auto.commit.interval.ms',1000);
                $rd_topic_conf -> set("offset.store.path", $this->offset_path);
                $rd_topic_conf -> set('auto.offset.reset','smallest');
                //$rd_topic_conf -> set('offset.store.method','broker');//flie(offset.store.path),broker
                //$rd_topic_conf -> set('offset.store.sync.interval.ms',60000);//fsync() interval for the offset file, in milliseconds. Use -1 to disable syncing, and 0 for immediate sync after each write.

                $this->consumer_topic = $this->consumer->newTopic($topic_name,$rd_topic_conf);
                break;
        }

    }

//-----------------------------------produce start-----------------------------------

    public function produce($message, $partition = self::PRODUCER_PARTITION_MODE_RANDOM , $key = null){

        if(!isset($this->producer) || trim($message) == '' || !is_numeric($partition) || $partition < -2 ){
            echo "error : produce wrong param";
            return false;
        }
        try {

            switch ($partition) {
            //一致性hash发送消息
            case self::PRODUCER_PARTITION_MODE_CONSISTENT:
                if(isset($key) && $key !=''){
                   $this->setProducerTopic('consistent');
                   $this->producer_topic-> produce(RD_KAFKA_PARTITION_UA, 0, $message,$key);
                }else{
                    echo "error : produce no key";
                    exit;
                }
                break;

            //随机发送
            case self::PRODUCER_PARTITION_MODE_RANDOM:

                $this->setProducerTopic();
                $this->producer_topic-> produce(RD_KAFKA_PARTITION_UA, 0, $message);
                break;
            //指定partition发送
            default:
                $this->setProducerTopic();

                $this->setPartitions($this->producer,$this->producer_topic);

                if(!in_array($partition,$this->all_partitions,true)){
                    echo "error : produce no partition ".$partition." in ".$this->topic_name." (".implode(",",$this->all_partitions).") ";
                    exit;
                }
                $this->producer_topic -> produce($partition, 0, $message);
                break;
            }

        } catch (Exception $e) {
            echo $e->getMessage();
            return false;
        }
        return true;
    }

    private function setProducerTopic($mode = ''){

        if(!$this->producer_topic){
            if(!isset($this->producer)){
                echo "error : produce wrong identity";
                exit;
            }
            $rd_topic_conf = new RdKafka\TopicConf();

            $rd_topic_conf->set("request.required.acks", self::PRODUCER_REQUEST_ACK); 

            if($mode == 'consistent'){

                $rd_topic_conf -> setPartitioner(RD_KAFKA_MSG_PARTITIONER_CONSISTENT);
            }
            //print_r($rd_topic_conf->dump());exit;
            $this->producer_topic = $this->producer->newTopic($this->topic_name,$rd_topic_conf);

        }
    }
//-----------------------------------produce end-----------------------------------


//-----------------------------------consume start-----------------------------------

    //@todo 存储方式：zk
    public function consume($partition=null){

        if(!isset($this->consumer_topic) || !isset($this->consumer)){
            echo "error : consume wrong";
            return false;
        }

        $this->setQueue($partition);

        $msg = $this->queue->consume(self::OPERATE_TIMEOUT_MS);

        if(isset($msg)){
            if ($msg->err != 0 ) {
                echo "topic[$msg->topic_name] - partition[$msg->partition] - offset[$msg->offset] : msg-".$msg->errstr()."\n";
                return false;
            }
            echo "topic[$msg->topic_name] - partition[$msg->partition] - offset[$msg->offset] : msg-";
            return $msg->payload;
        }
    }   

    private function setQueue($partition){

        if(!$this->queue){
            $this->queue = $this->consumer -> newQueue();

            $this->setPartitions($this->consumer,$this->consumer_topic);

            if(is_numeric($partition) && $partition >= 0){
               $partition = array($partition);
            }

            if(is_array($partition)){

                foreach ($partition as $p) {
                    if(!in_array($p,$this->all_partitions,true)){
                        echo "error : consume no partition ".$partition." in ".$this->topic_name." (".implode(",",$this->all_partitions).") ";
                        exit;
                    }
                }
                $consume_partitions = $partition;

            }else{

                $consume_partitions = $this->all_partitions;

            }
            foreach ($consume_partitions as  $p) {
                $this->consumer_topic -> consumeQueueStart($p, RD_KAFKA_OFFSET_STORED,$this->queue);//RD_KAFKA_OFFSET_STORED
            }

        }
    }

//-----------------------------------consume end-----------------------------------


//-----------------------------------public start-----------------------------------

    private function setPartitions($kd_obj,$topic_obj){

        if(!$this->all_partitions){
            if(!isset($topic_obj) || !isset($kd_obj)){
                echo "error : get partitions wrong";
                exit;
            }
           
            $metadata = $kd_obj->metadata(false,$topic_obj,self::OPERATE_TIMEOUT_MS)->getTopics();
            $this->all_partitions =array();
            foreach ($metadata as $topic) {
                foreach ($topic->getPartitions() as $k => $p) {
                    $this->all_partitions[] = $p->getId();
                }
            }
        }
    }   

//-----------------------------------public end-----------------------------------
}
?>