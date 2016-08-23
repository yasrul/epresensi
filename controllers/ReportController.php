<?php

namespace app\controllers;

use Yii;
use yii\web\Controller;
use yii\filters\AccessControl;
use yii\filters\VerbFilter;
use yii\data\SqlDataProvider;
use yii\data\ArrayDataProvider;
use yii\helpers\Json;

use app\models\ReportForm;
use app\models\Departments;
use app\models\Userinfo;
use app\models\KeteranganAbsen;
use app\models\TglLibur;
use app\models\JamKerja;
use app\models\PermissionHelpers;

class ReportController extends Controller
{
    public function behaviors()
    {
        return [
            'access' => [
                'class'=> AccessControl::className(),
                'only'=>['index','day-report','resume-report'],
                'rules'=>[
                    [
                        'actions'=>['index','day-report','resume-report'],
                        'allow'=>TRUE,
                        'roles'=>['@'],
                        'matchCallback' => function ($rule, $action) {
                            return PermissionHelpers::requireMinimumRole('User') &&
                            PermissionHelpers::requireStatus('Active');
                        }
                    ]
                    
                ]
            ],
            'verbs' => [
                'class' => VerbFilter::className(),
                'actions' => [
                    'logout' => ['post'],
                ],
            ],
        ];
    }
    
    public function actionIndex()
    {
        return $this->render('index');
        
    }
      
    public function actionEselon3List() {
        $out = [];
        if (isset($_POST['depdrop_parents'])) {
            $parents = $_POST['depdrop_parents'];
            if ($parents != NULL) {
                $skpdID = $parents[0];
                $out = Departments::find()->where(['supdeptid' => $skpdID])
                        ->select(['DeptID as id', 'DeptName as name'])->asArray()->all();
                $params = NULL;
                if(!empty($_POST['depdrop_params'])) {
                    $params = $_POST['depdrop_params'];
                    $selected = $params[0];
                }
               
                echo Json::encode(['output' => $out, 'selected' =>$selected]);
                return;
            }
        }
        echo Json::encode(['output' => '', 'selected'=>'']);
    }

    public function actionEselon4List() {
        $out = [];
        if (isset($_POST['depdrop_parents'])) {
            $parents = $_POST['depdrop_parents'];
            $eselon3_id = empty($parents[0]) ? NULL : $parents[0];
            
            if ($eselon3_id != NULL) {
                $out = Departments::find()->where(['supdeptid' => $eselon3_id])
                        ->select(['DeptID as id', 'DeptName as name'])->asArray()->all();
                
                $params=NULL;
                if (!empty($_POST['depdrop_params'])) {
                    $params = $_POST['depdrop_params'];
                    $selected = $params[0];
                }
                
                echo Json::encode(['output' => $out, 'selected' => $selected]);
                return;
            }
        }
        echo Json::encode(['output' => '', 'selected'=>'']);
    }

    public function actionDayReport() {
        $model = new ReportForm(); 
        $model->load(Yii::$app->request->queryParams);
        
        $dataProvider = new ArrayDataProvider([
            'allModels' => $this->arrayDayReport($model),
            'pagination'=> [
                'pageSize'=>30,
            ]
        ]);
             
        return $this->render('report-day', [
            'model'=>$model,
            'dataProvider'=>$dataProvider
        ]);
        
    }
    
    public function actionResumeReport() {
        $model = new ReportForm();
        $model->load(\Yii::$app->request->queryParams);
        
        $dataProvider = new ArrayDataProvider([
            'allModels' => $this->arrayResumeReport($model),
            'pagination'=> [
                'pageSize'=>20,
            ]
        ]);
        
        return $this->render('report-resume', [
            'model'=>$model,
            'dataProvider'=>$dataProvider
        ]);
    }
    
    public function arrayDayReport($model) {
        $deptids = [];
        if ($model->eselon4 != NULL) {
            $deptids=[$model->eselon4];
        }elseif ($model->eselon3 != NULL) {
            $eselon3s = [$model->eselon3];
            $eselon4s = Yii::$app->db->createCommand('select DeptID from departments where supdeptid =:deptid')
                    ->bindValue(':deptid', $eselon3s)->queryAll();
            $deptids = array_merge($eselon3s, $eselon4s);          
        }elseif ($model->skpd !=NULL) {
            $skpd = [$model->skpd];
            $eselon3s = Yii::$app->db->createCommand('select DeptID from departments where supdeptid =:deptid')
                    ->bindValue(':deptid', $skpd)->queryAll();
            if(count($eselon3s)) {
                foreach ($eselon3s as $eselon3 ) {
                    $eselon4s[] = Yii::$app->db->createCommand('select DeptID from departments where supdeptid =:deptid')
                    ->bindValue(':deptid', $eselon3['DeptID'])->queryAll();
                }
                $deptids = array_merge_recursive($skpd, $eselon3s, $eselon4s);
            } else $deptids = $skpd;
        }
        $deptids = implode(",", $deptids);
               
        $tglAwal = new \DateTime($model->tglAwal);
        if(in_array($tglAwal->format('w'),[1,2,3,4])) {
            $jamKerja = JamKerja::find()->where(['nama_jamker'=>'senin-kamis'])->one();
        }elseif($tglAwal->format('w') == 5) {
            $jamKerja = JamKerja::find()->where(['nama_jamker'=>'jumat'])->one();
        }
        
       $query = 'SELECT u.userid, u.name, IF(COUNT(c.checktime) > 0, MIN(c.checktime),"Nihil" ) AS datang, '.
                'IF(COUNT(c.checktime) > 1, MAX(c.checktime),"Nihil" ) AS pulang, '.
                'IF(k.statusid IS NULL, IF(TIME(MIN(c.checktime)) > :jamMasuk OR TIME(MAX(c.checktime)) < :jamPulang, "TH/CP",""), k.statusid) AS keterangan '.
                'FROM userinfo u '.
                'LEFT JOIN checkinout c ON u.userid=c.userid AND DATE(c.checktime)=:tgl '.
                'LEFT JOIN keterangan_absen k ON u.userid=k.userid AND :tgl BETWEEN k.tgl_awal AND (IF(k.tgl_akhir IS NULL, k.tgl_awal, k.tgl_akhir)) '.
                'WHERE u.defaultdeptid IN (:deptids) '.
                'GROUP BY u.userid, DATE(c.checktime) '.
                'ORDER BY u.userid ASC ';
        
        $cmd = Yii::$app->db->createCommand($query);
        $cmd->bindValues([':tgl'=>$model->tglAwal, ':deptids'=> $deptids]);
        $cmd->bindValues([':jamMasuk'=>$jamKerja->jam_masuk, ':jamPulang'=>$jamKerja->jam_pulang]);
        $allModels = $cmd->queryAll();
        
        return $allModels;   
    }
    
    public function arrayResumeReport($model) {
        $deptids = [];
        if ($model->eselon4 != NULL) {
            $deptids=[$model->eselon4];
        }elseif ($model->eselon3 != NULL) {
            $eselon3s = [$model->eselon3];
            $eselon4s = Yii::$app->db->createCommand('select DeptID from departments where supdeptid =:deptid')
                    ->bindValue(':deptid', $eselon3s)->queryAll();
            $deptids = array_merge($eselon3s, $eselon4s);          
        }elseif ($model->skpd !=NULL) {
            $skpd = [$model->skpd];
            $eselon3s = Yii::$app->db->createCommand('select DeptID from departments where supdeptid =:deptid')
                    ->bindValue(':deptid', $skpd)->queryAll();
            if(count($eselon3s)) {
                foreach ($eselon3s as $eselon3 ) {
                    $eselon4s[] = Yii::$app->db->createCommand('select DeptID from departments where supdeptid =:deptid')
                    ->bindValue(':deptid', $eselon3['DeptID'])->queryAll();
                }
                $deptids = array_merge_recursive($skpd, $eselon3s, $eselon4s);
            } else $deptids = $skpd;
        }
        $deptids = implode(",", $deptids);
        
        $tglAwal = new \DateTime($model->tglAwal);
        if(in_array($tglAwal->format('w'),[1,2,3,4])) {
            $jamKerja = JamKerja::find()->where(['nama_jamker'=>'senin-kamis'])->one();
        }elseif($tglAwal->format('w') == 5) {
            $jamKerja = JamKerja::find()->where(['nama_jamker'=>'jumat'])->one();
        }
        
        $renAwal = $model->tglAwal;
        if($model->tglAkhir == NULL) {
            $renAkhir = $renAwal;
        }else $renAkhir = $model->tglAkhir;   
                
        $query = Userinfo::find()->with([
            'keteranganAbsen'=>function($query) use($renAwal, $renAkhir) {
                $query->where('(tgl_awal >= :renAwal and tgl_awal <= :renAkhir) or '
                        . '(tgl_akhir >= :renAwal and tgl_akhir <= :renAkhir)',[':renAwal'=>$renAwal, ':renAkhir'=>$renAkhir]);
            },
            'checkinoutsDaily'=>function ($query) use($renAwal, $renAkhir) {
                $query->andWhere('DATE(datang) >= :renAwal and DATE(datang) <= :renAkhir',[':renAwal'=>$renAwal, ':renAkhir'=>$renAkhir]);
            }
            ])    
        ->where('defaultdeptid IN (:deptids)', [':deptids'=>$deptids])
        ->all();
        
        $allModels = [];
        
        $renAwal = new \DateTime($model->tglAwal);
        if($model->tglAkhir == NULL) {
            $renAkhir = $renAwal;
        }else $renAkhir = new \DateTime($model->tglAkhir);
        
        foreach ($query as $userInfo) {
            $jmlSakit = 0;
            $jmlIjin =0;
            $jmlTD=0;
            $jmlCuti=0;
            $jmlAlpa=0;
            $jmlTHCP=0;
            
            if(count($userInfo->keteranganAbsen)) {
                foreach ($userInfo->keteranganAbsen as $ketAbsen) {
                    
                    if ($ketAbsen->tgl_akhir == NULL) {
                        $tglAkhir = new \DateTime($ketAbsen->tgl_awal);
                    } else $tglAkhir = new \DateTime($ketAbsen->tgl_akhir);                                     
                    $tglAwal = new \DateTime($ketAbsen->tgl_awal);                  
                    
                    if ($tglAwal < $renAwal) $tglAwal = $renAwal;
                    if ($tglAkhir > $renAkhir) $tglAkhir =$renAkhir;
                        
                    if($ketAbsen->statusid == 'S') {                                                   
                        $jmlSakit = $jmlSakit + $tglAkhir->diff($tglAwal)->format("%a")+1;
                            
                    } elseif ($ketAbsen->statusid == 'I') {                                                      
                        $jmlIjin = $jmlIjin + $tglAkhir->diff($tglAwal)->format("%a")+1;
                            
                    } elseif ($ketAbsen->statusid == 'TD') {                                                     
                        $jmlTD = $jmlTD + $tglAkhir->diff($tglAwal)->format("%a")+1;
                            
                    } elseif ($ketAbsen->statusid == 'C') {                                                      
                        $jmlCuti = $jmlCuti + $tglAkhir->diff($tglAwal)->format("%a")+1;
                            
                    }                                 
                }               
            }
            if (count($userInfo->checkinoutsDaily)) {
                foreach ($userInfo->checkinoutsDaily as $checkinout) {
                    
                    $tglDatang = new \DateTime($checkinout->datang);
                    if ($checkinout->pulang == NULL) {
                        $tglPulang = $tglDatang;
                    }else $tglPulang = new \DateTime($checkinout->pulang);
                    
                    if ( ! (TglLibur::find()->where(['tgl_libur'=>$tglDatang->format('Y-m-d')])->one() OR in_array($tglDatang->format('w'),[0,6]))) {
                    
                        $Ada1 = KeteranganAbsen::find()->where(['userid'=>$userInfo->userid])
                                ->andWhere(['tgl_awal'=> $tglDatang->format('Y-m-d')])
                                ->andWhere(['IS', 'tgl_akhir', NULL])
                                ->exists();
                            
                        $Ada2 = KeteranganAbsen::find()->where(['userid'=>$userInfo->userid])
                                ->andWhere(['<=','tgl_awal', $tglDatang->format('Y-m-d')])
                                ->andWhere(['>=','tgl_akhir', $tglDatang->format('Y-m-d')])
                                ->exists();
                    
                        if ( ! ($Ada1 || $Ada2) ) {
                            if ($tglDatang->format('H:i:s') > $jamKerja->jam_masuk OR $tglPulang->format('H:i:s') < $jamKerja->jam_pulang) {
                                $jmlTHCP = $jmlTHCP +1;
                            }
                        }
                    }                    
                }
            } 
            $allModels[]=[
                'userid'=>$userInfo->userid,
                'name'=>$userInfo->name,
                'sakit'=>$jmlSakit,
                'ijin'=>$jmlIjin,
                'tugas-dinas'=>$jmlTD,
                'cuti'=>$jmlCuti,
                'th-cp'=>$jmlTHCP,
                'alpa'=>$jmlAlpa,
            ];
        }
        return $allModels;
    }

    public function actionRepdayExcel(array $params) {
        $model = new ReportForm;
        $model->tglAwal=$params['tglAwal'];
        $model->skpd=$params['skpd'];
               
        $dataProvider = new ArrayDataProvider([
            'allModels' => $this->arrayDayReport($model),
            'pagination'=> [
                'pageSize'=>FALSE,
            ]
        ]);
        
        $objReader = \PHPExcel_IOFactory::createReader('Excel2007');
        //set template
        $template = Yii::getAlias('@app/views/report').'/_dayrep.xlsx';
        $objPHPExcel = $objReader->load($template);
        $activeSheet = $objPHPExcel->getActiveSheet();
        // set orientasi dan ukuran kertas
        $activeSheet->getPageSetup()
                ->setOrientation(\PHPExcel_Worksheet_PageSetup::ORIENTATION_LANDSCAPE)
                ->setPaperSize(\PHPExcel_Worksheet_PageSetup::PAPERSIZE_FOLIO);
        
        $baseRow=3;
        foreach ($dataProvider->getModels() as $absen) {
            $activeSheet->setCellValue('A'.$baseRow, $baseRow-2)
                    ->setCellValue('B'.$baseRow, $absen['userid'])
                    ->setCellValue('C'.$baseRow, $absen['name'])
                    ->setCellValue('D'.$baseRow, $absen['datang'])
                    ->setCellValue('E'.$baseRow, $absen['pulang']);
            $baseRow++;
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="_dayrep.xlsx"');
        header('Cache-Control: max-age=0');
        $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, "Excel2007");
        $objWriter->save('php://output');
        exit;
    }
    
    public function actionRepdayPdf(array $params) {
        $model = new ReportForm;
        $model->tglAwal = $params['tglAwal'];
        $model->skpd = $params['skpd'];
        
        $dataProvider = new ArrayDataProvider([
            'allModels' => $this->arrayDayReport($model),
            'pagination'=> [
                'pageSize'=>FALSE,
            ]
        ]);
        
        $html = $this->renderPartial('_dayrep', ['dataProvider'=>$dataProvider]);
        
        $mpdf = new \mPDF('c', 'A4','','',0,0,0,0,0,0);
        $mpdf->SetDisplayMode('fullpage');
        $mpdf->list_indent_first_level = 0;
        $mpdf->WriteHTML($html);
        $mpdf->Output();
        
        exit;
    }
    
        public function actionRepresumeExcel(array $params) {
        $model = new ReportForm;
        $model->tglAwal=$params['tglAwal'];
        $model->tglAkhir=$params['tglAkhir'];
        $model->skpd=$params['skpd'];
               
        $dataProvider = new ArrayDataProvider([
            'allModels' => $this->arrayResumeReport($model),
            'pagination'=> ['pageSize'=>FALSE]
        ]);
        
        $objReader = \PHPExcel_IOFactory::createReader('Excel2007');
        //set template
        $template = Yii::getAlias('@app/views/report').'/_dayrep.xlsx';
        $objPHPExcel = $objReader->load($template);
        $activeSheet = $objPHPExcel->getActiveSheet();
        // set orientasi dan ukuran kertas
        $activeSheet->getPageSetup()
                ->setOrientation(\PHPExcel_Worksheet_PageSetup::ORIENTATION_LANDSCAPE)
                ->setPaperSize(\PHPExcel_Worksheet_PageSetup::PAPERSIZE_FOLIO);
        
        $baseRow=3;
        foreach ($dataProvider->getModels() as $absen) {
            $activeSheet->setCellValue('A'.$baseRow, $baseRow-2)
                    ->setCellValue('B'.$baseRow, $absen['userid'])
                    ->setCellValue('C'.$baseRow, $absen['name'])
                    ->setCellValue('D'.$baseRow, $absen['sakit'])
                    ->setCellValue('E'.$baseRow, $absen['ijin'])
                    ->setCellValue('F'.$baseRow, $absen['tugas-dinas'])
                    ->setCellValue('G'.$baseRow, $absen['cuti'])
                    ->setCellValue('H'.$baseRow, $absen['th-cp'])
                    ->setCellValue('E'.$baseRow, $absen['alpa']);
            $baseRow++;
        }
        
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment;filename="_dayrep.xlsx"');
        header('Cache-Control: max-age=0');
        $objWriter = \PHPExcel_IOFactory::createWriter($objPHPExcel, "Excel2007");
        $objWriter->save('php://output');
        exit;
    }
    
        public function actionRepresumePdf(array $params) {
        $model = new ReportForm;
        $model->tglAwal = $params['tglAwal'];
        $model->tglAkhir = $params['tglAkhir'];
        $model->skpd = $params['skpd'];
        
        $dataProvider = new ArrayDataProvider([
            'allModels' => $this->arrayResumeReport($model),
            'pagination'=> ['pageSize'=>FALSE]
        ]);
        
        $html = $this->renderPartial('_resumerep', ['dataProvider'=>$dataProvider]);
        
        $mpdf = new \mPDF('c','A4','','',0,0,0,0,0,0);
        $mpdf->SetDisplayMode('fullpage');
        $mpdf->list_indent_first_level = 0;
        $mpdf->WriteHTML($html);
        $mpdf->Output();

        exit;
    }

}
