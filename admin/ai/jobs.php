<?php
session_start();
require_once '../../config/database.php';
require_once '../../includes/functions.php';
$current_page='ai-jobs';
require_once '../includes/header.php';
?>
<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-3"><div><h1 class="h3 mb-1">Hàng đợi AI</h1><p class="text-muted mb-0">Tác vụ vẫn tiếp tục sau khi đóng tab nếu Cron worker đang hoạt động.</p></div><button class="btn btn-outline-primary" id="refreshJobs"><i class="bi bi-arrow-clockwise"></i> Làm mới</button></div>
  <div id="workerState" class="alert alert-secondary">Đang đọc trạng thái worker...</div>
  <div class="card"><div class="card-body"><div class="row g-2 mb-3"><div class="col-sm-4"><select id="statusFilter" class="form-select"><option value="">Tất cả trạng thái</option><option value="queued">Đang chờ</option><option value="running">Đang chạy</option><option value="retry_wait">Chờ thử lại</option><option value="succeeded">Thành công</option><option value="failed">Lỗi</option><option value="cancelled">Đã hủy</option></select></div></div><div class="table-responsive"><table class="table align-middle"><thead><tr><th>ID</th><th>Tác vụ</th><th>Đối tượng</th><th>Trạng thái</th><th>Bước</th><th>Cập nhật</th><th></th></tr></thead><tbody id="jobRows"></tbody></table></div><button id="moreJobs" class="btn btn-outline-secondary" hidden>Xem thêm</button></div></div>
  <div class="modal fade" id="jobModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5">Chi tiết tác vụ</h2><button class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body"><div id="jobDetail"></div><pre id="jobResult" class="bg-light border rounded p-3 mt-3" style="max-height:420px;overflow:auto;white-space:pre-wrap"></pre></div><div class="modal-footer"><button id="cancelJob" class="btn btn-outline-danger">Hủy</button><button id="retryJob" class="btn btn-outline-primary">Thử lại</button><button class="btn btn-secondary" data-bs-dismiss="modal">Đóng</button></div></div></div></div>
</div>
<script>
(function(){
 const rows=document.getElementById('jobRows'), state=document.getElementById('workerState'), more=document.getElementById('moreJobs'), filter=document.getElementById('statusFilter'); let page=1,current=null;
 const initialBatch=new URLSearchParams(location.search).get('batch_id')||'';
 const labels={queued:'Đang chờ Cron',running:'Đang chạy',retry_wait:'Chờ thử lại',succeeded:'Thành công',failed:'Lỗi',cancelled:'Đã hủy'};
 function cell(tr,text){const td=document.createElement('td');td.textContent=text;tr.append(td);}
 async function load(reset=true){if(reset){page=1;rows.replaceChildren();}const d=await AIJobs.list({page,status:filter.value,batch_id:initialBatch});const warn=AIJobs.workerWarning(d.worker);state.className='alert '+(warn?'alert-warning':'alert-success');state.textContent=warn||('Worker đang hoạt động. Trạng thái: '+(d.worker.state||'idle'));
 (d.jobs||[]).forEach(j=>{const tr=document.createElement('tr');cell(tr,'#'+j.id);cell(tr,j.kind+' / '+j.action);cell(tr,j.entity_id?'#'+j.entity_id:'—');cell(tr,labels[j.status]||j.status);cell(tr,j.stage||'—');cell(tr,j.updated_at||'');const td=document.createElement('td');const b=document.createElement('button');b.className='btn btn-sm btn-outline-secondary';b.textContent='Chi tiết';b.onclick=()=>detail(j.id);td.append(b);tr.append(td);rows.append(tr);});more.hidden=!d.has_more;}
 async function detail(id){const d=await AIJobs.list({id});current=d.jobs[0];document.getElementById('jobDetail').textContent='#'+current.id+' — '+(labels[current.status]||current.status)+' — '+(current.message||'');document.getElementById('jobResult').textContent=current.result?JSON.stringify(current.result,null,2):'Chưa có kết quả.';document.getElementById('retryJob').hidden=!['failed','cancelled'].includes(current.status);document.getElementById('cancelJob').hidden=!['queued','running','retry_wait'].includes(current.status);bootstrap.Modal.getOrCreateInstance(document.getElementById('jobModal')).show();}
 document.getElementById('refreshJobs').onclick=()=>load(true);filter.onchange=()=>load(true);more.onclick=()=>{page++;load(false)};document.getElementById('retryJob').onclick=async()=>{if(current){await AIJobs.action('retry',current.id);bootstrap.Modal.getInstance(document.getElementById('jobModal')).hide();load(true)}};document.getElementById('cancelJob').onclick=async()=>{if(current){await AIJobs.action('cancel',current.id);bootstrap.Modal.getInstance(document.getElementById('jobModal')).hide();load(true)}};
 load(true).catch(e=>{state.className='alert alert-danger';state.textContent=e.message});
})();
</script>
<?php require_once '../includes/footer.php'; ?>
