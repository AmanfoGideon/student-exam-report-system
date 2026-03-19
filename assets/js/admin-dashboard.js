/**
 * =====================================================
 * ADMIN DASHBOARD CONTROLLER (PRO EDITION)
 * Clean, modular, and scalable
 * =====================================================
 */
document.addEventListener("DOMContentLoaded", () => {
  /* ------------------------------------------
   * CORE SELECTORS & CONFIG
   * ------------------------------------------ */
  const $ = id => document.getElementById(id);
  const loader = $("pageLoader");
  const heatmapContainer = $("heatmapContainer");
  const alertsArea = $("alertsArea");
  const activityLog = $("activityLog");

  const API_URL = "dashboard_logic.php";
  const DEBOUNCE_DELAY = 250;

  const chartDefaults = {
    responsive: true,
    maintainAspectRatio: false,
    animation: { duration: 600, easing: "easeOutQuart" },
    plugins: {
      legend: { position: "bottom", labels: { boxWidth: 10, padding: 8 } },
      tooltip: {
        backgroundColor: "#222",
        titleColor: "#fff",
        bodyColor: "#fff",
        callbacks: {
          label: ctx => `${ctx.dataset.label}: ${ctx.parsed.y ?? 0}`
        }
      }
    },
    scales: {
      y: { beginAtZero: true, ticks: { stepSize: 10 }, grid: { color: "#eee" } },
      x: { grid: { color: "#fafafa" } }
    }
  };

  let subjectAvgChart = null;
  let passFailChart = null;
  let fetchTimeout = null;

  /* ------------------------------------------
   * LOADER HANDLING
   * ------------------------------------------ */
  const toggleLoader = (show = true) => {
    if (!loader) return;
    loader.classList.toggle("visible", show);
  };

  // Automatically show loader during all network calls
  (() => {
    const originalFetch = window.fetch;
    window.fetch = async (...args) => {
      toggleLoader(true);
      try {
        return await originalFetch(...args);
      } finally {
        toggleLoader(false);
      }
    };
  })();

  /* ------------------------------------------
   * INITIALIZE CHARTS
   * ------------------------------------------ */
  const initCharts = () => {
    const ctx1 = $("subjectAvgChart");
    const ctx2 = $("passFailChart");

    if (ctx1) {
      subjectAvgChart = new Chart(ctx1, {
        type: "bar",
        data: { labels: [], datasets: [{ label: "Average Score", data: [], backgroundColor: "#1a73e8" }] },
        options: chartDefaults
      });
    }

    if (ctx2) {
      passFailChart = new Chart(ctx2, {
        type: "bar",
        data: {
          labels: [],
          datasets: [
            { label: "Pass", data: [], backgroundColor: "#28a745" },
            { label: "Fail", data: [], backgroundColor: "#dc3545" }
          ]
        },
        options: chartDefaults
      });
    }
  };
  initCharts();

  /* ------------------------------------------
   * FILTER HANDLING
   * ------------------------------------------ */
  const filterIds = ["yearFilter", "termFilter", "classFilter", "subjectFilter", "teacherFilter"];

  const getFilters = () =>
    filterIds.reduce((acc, id) => {
      acc[id.replace("Filter", "")] = $(id)?.value ?? "";
      return acc;
    }, {});

  const resetFilters = () => filterIds.forEach(id => ($(id).value = ""));

  /* ------------------------------------------
   * FETCH DASHBOARD DATA
   * ------------------------------------------ */
  const fetchDashboardData = () => {
    clearTimeout(fetchTimeout);
    fetchTimeout = setTimeout(async () => {
      heatmapContainer.innerHTML = `<div class="text-muted small">Loading...</div>`;

      try {
        const res = await fetch(API_URL, {
          method: "POST",
          headers: { "Content-Type": "application/x-www-form-urlencoded" },
          body: new URLSearchParams(getFilters())
        });

        const { data, error } = await res.json();
        if (!res.ok || error) throw new Error(error || "Failed to load dashboard data");

        updateKPIs(data.kpis);
        updateCharts(data.charts);
        renderHeatmap(data.heatmap, data.charts.subjectAvgChart.labels);
        renderAlerts(data.alerts);
        renderActivityLog(data.activities);
      } catch (err) {
        console.error("Dashboard Error:", err);
        heatmapContainer.innerHTML = `<div class="alert alert-danger small mb-0">
          Error loading data: ${err.message}</div>`;
      }
    }, DEBOUNCE_DELAY);
  };

  /* ------------------------------------------
   * DOM RENDER HELPERS
   * ------------------------------------------ */
  const updateKPIs = kpis => {
    const set = (id, val, suffix = "") => {
      const el = $(id);
      if (el) el.textContent = (val ?? "—") + suffix;
    };
    set("kpi_total_students", kpis.total_students);
    set("kpi_reports_generated", kpis.reports_generated);
    set("kpi_pending_entries", kpis.pending_entries);
    set("kpi_pass_rate", kpis.overall_pass_rate, "%");
  };

  const updateCharts = charts => {
    if (subjectAvgChart) {
      subjectAvgChart.data.labels = charts.subjectAvgChart.labels ?? [];
      subjectAvgChart.data.datasets[0].data = charts.subjectAvgChart.data ?? [];
      subjectAvgChart.update();
    }

    if (passFailChart) {
      const { labels = [], pass = [], fail = [] } = charts.passFailChart;
      passFailChart.data.labels = labels;
      passFailChart.data.datasets[0].data = pass;
      passFailChart.data.datasets[1].data = fail;
      passFailChart.update();
    }
  };

  const renderHeatmap = (heatmap, subjects = []) => {
    if (!Object.keys(heatmap || {}).length || !subjects.length) {
      heatmapContainer.innerHTML = `<div class="text-muted small">No data available.</div>`;
      return;
    }

    const table = document.createElement("table");
    table.className = "table table-sm table-bordered heatmap-table";

    const headerRow = `<tr><th>Class</th>${subjects.map(s => `<th>${s}</th>`).join("")}</tr>`;
    const bodyRows = Object.entries(heatmap)
      .map(([cls, vals]) => `<tr><td>${cls}</td>${subjects.map(s => `<td>${vals?.[s] ?? "-"}</td>`).join("")}</tr>`)
      .join("");

    table.innerHTML = `<thead>${headerRow}</thead><tbody>${bodyRows}</tbody>`;
    heatmapContainer.replaceChildren(table);
  };

  const renderAlerts = alerts => {
    alertsArea.innerHTML = alerts?.length
      ? alerts.map(a =>
          `<div class="alert alert-info py-1 mb-1 fade show">
            ${a.message} <small>(${a.created_at})</small>
          </div>`).join("")
      : `<div class="text-muted small">No alerts.</div>`;
  };

  const renderActivityLog = logs => {
    activityLog.innerHTML = logs?.length
      ? logs.map(a => `<li>${a.action} <small class="text-muted">(${a.created_at})</small></li>`).join("")
      : `<li class="text-muted small">No recent activity.</li>`;
  };

  /* ------------------------------------------
   * EVENT LISTENERS
   * ------------------------------------------ */
  $("applyFiltersBtn")?.addEventListener("click", fetchDashboardData);
  $("resetFiltersBtn")?.addEventListener("click", () => {
    resetFilters();
    fetchDashboardData();
  });

  /* ------------------------------------------
   * INITIAL LOAD
   * ------------------------------------------ */
  fetchDashboardData();

  // Optional: export for debugging
  window.Dashboard = { fetchDashboardData, toggleLoader, updateCharts };
});
