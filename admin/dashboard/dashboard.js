document.addEventListener('DOMContentLoaded', function(){
  fetch('dashboard_action.php')
  .then(res=>res.json())
  .then(data=>{
    if(!data.success) return;

    document.getElementById('kpi-students').textContent = data.students;
    document.getElementById('kpi-teachers').textContent = data.teachers;
    document.getElementById('kpi-classes').textContent = data.classes;
    document.getElementById('kpi-subjects').textContent = data.subjects;
    document.getElementById('kpi-exams').textContent = data.exams;

    // Activity log
    const log = document.getElementById('activity-log');
    data.activities.forEach(a=>{
      const li = document.createElement('li');
      li.className = 'list-group-item';
      li.textContent = `${a.first_name||''} ${a.last_name||''} - ${a.report_type} on ${a.generated_at}`;
      log.appendChild(li);
    });

    // Charts
    new Chart(document.getElementById('chartClassAvg'), {
      type: 'bar',
      data: {
        labels: data.class_avg.labels,
        datasets: [{label:'Average', data: data.class_avg.data, backgroundColor:'#003399'}]
      },
      options:{responsive:true, scales:{y:{beginAtZero:true}}}
    });

    new Chart(document.getElementById('chartSubjectAvg'), {
      type: 'line',
      data: {
        labels: data.subject_avg.labels,
        datasets: [{label:'Average', data: data.subject_avg.data, borderColor:'#0b3d91', fill:false, tension:0.3}]
      },
      options:{responsive:true, scales:{y:{beginAtZero:true}}}
    });
  });
});
