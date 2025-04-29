function renderTable(devices, trafficStats, resourcesStats) {
  const tableContainer = document.getElementById('device-table');

  const openedSections = new Set();
  document.querySelectorAll('#device-table tr:not(.hidden)').forEach(row => {
    const id = row.id;
    if (id && (id.startsWith('traffic-') || id.startsWith('resources-'))) {
      openedSections.add(id);
    }
  });

  let html = `
    <table>
      <thead>
        <tr>
          <th>ID</th>
          <th>Имя устройства</th>
          <th>IP-адрес</th>
          <th>Маска подсети</th>
          <th>Логин</th>
          <th>Пароль</th>
          <th>Passwd enabled</th>
          <th>Действия</th>
        </tr>
      </thead>
      <tbody>
  `;

  devices.forEach(device => {
    html += `
      <tr>
        <td>${device.id}</td>
        <td>${device.hostname}</td>
        <td>${device.ip_addr}</td>
        <td>${device.netmask}</td>
        <td>${device.login}</td>
        <td>${device.passwd}</td>
        <td>${device.passwd_enabled == 0 ? 'false' : 'true'}</td>
        <td>
          <button class="primary-button" onclick="toggleVisibility('traffic-${device.id}')">Интерфейсы</button>
          <button class="primary-button" onclick="toggleVisibility('resources-${device.id}')">Ресурсы</button>
        </td>
      </tr>

      <tr id="traffic-${device.id}" class="${openedSections.has(`traffic-${device.id}`) ? '' : 'hidden'}">
        <td colspan="8">
          <table>
            <thead>
              <tr>
                <th>Интерфейс</th>
                <th>In Unicast (пакеты)</th>
                <th>Out Unicast (пакеты)</th>
                <th>In Multicast (пакеты)</th>
                <th>Out Multicast (пакеты)</th>
              </tr>
            </thead>
            <tbody>
              ${trafficStats.filter(t => t.device_id === device.id).map(t => `
                <tr>
                  <td>${t.interface}</td>
                  <td>${t.in_unicast}</td>
                  <td>${t.out_unicast}</td>
                  <td>${t.in_multicast}</td>
                  <td>${t.out_multicast}</td>
                </tr>
              `).join('')}
            </tbody>
          </table>
        </td>
      </tr>

      <tr id="resources-${device.id}" class="${openedSections.has(`resources-${device.id}`) ? '' : 'hidden'}">
        <td colspan="8">
          <table>
            <thead>
              <tr>
                <th>CPU Загрузка</th>
                <th>Диски</th>
                <th>Оперативная память</th>
              </tr>
            </thead>
            <tbody>
              ${(() => {
        const r = resourcesStats.find(res => res.device_id === device.id);
        return `
                  <tr>
                    <td>${r.cpu_load == 0 ? '<Неизвестно>' : r.cpu_load} %</td>
                    <td>
                    ${(() => {
            let d = '';
            r.disks.forEach(disk => {
              d += `${disk.disk_name}: ${disk.disk_usage == 0 ? '<Неизвестно>' : disk.disk_usage} ГБ / ${disk.disk_total == 0 ? '<Неизвестно>' : disk.disk_total} ГБ <br> `;
            });
            return d;
          })()}
                    </td>
                    <td>${r.memory_usage == 0 ? '<Неизвестно>' : r.memory_usage} ГБ / ${r.memory_total == 0 ? '<Неизвестно>' : r.memory_total} ГБ</td>
                  </tr>
                `;
      })()}
            </tbody>
          </table>
        </td>
      </tr>
    `;
  });

  html += `</tbody></table>`;
  tableContainer.innerHTML = html;
}

function toggleVisibility(id) {
  const el = document.getElementById(id);
  el.classList.toggle('hidden');
}

function showNotifications(logMessages) {
  notificationDropdown.innerHTML = '';

  if (logMessages.length === 0) {
    notificationDropdown.innerHTML = '<div class="notification-item">Нет ошибок.</div>';
  } else {
    logMessages.forEach(log => {
      const item = document.createElement('div');
      item.classList.add('notification-item');
      item.classList.add(log.status === 'success' ? 'notification-success' : 'notification-error');
      item.textContent = `${log.status === 'success' ? '✅' : '❌'} ${log.message}`;
      notificationDropdown.appendChild(item);
    });
  }

  const errorCount = logMessages.filter(log => log.status !== 'success').length;
  notificationButton.textContent = `⚠️ (${errorCount})`;
}

function get_data() {
  fetch('../backend/get_data.php')
    .then(response => response.text())
    .then(text => {
      try {
        const data = JSON.parse(text);
        renderTable(data.devices, data.trafficStats, data.resourcesStats);
        showNotifications(data.logMessages);
      } catch (error) {
        console.error('Ошибка разбора JSON:', error);
        showNotifications([{ status: 'failure', message: `Ошибка обработки данных с сервера: ${error.message}` }]);
      }
    })
    .catch(error => {
      showNotifications([{ status: 'failure', message: `Ошибка загрузки данных: ${error.message}` }]);
    });
}

function generate_data() {
  const loader = document.getElementById('loader');

  loader.classList.remove('hidden');
  refreshButton.disabled = true;
  refreshIntervalInput.disabled = true;
  refreshSubmitButton.disabled = true;

  fetch('../backend/generate_data.php')
    .then(() => new Promise(resolve => setTimeout(resolve, 3000)))
    .then(() => get_data())
    .catch(error => {
      console.error('Ошибка запуска опроса:', error);
      showNotifications([{ status: 'failure', message: `Ошибка запуска опроса: ${error.message}` }]);
    })
    .finally(() => {
      loader.classList.add('hidden');
      refreshButton.disabled = false;
      refreshIntervalInput.disabled = false;
      refreshSubmitButton.disabled = false;
    });
}

function startAutoRefresh() {
  clearInterval(generateDataInterval);
  generateDataInterval = setInterval(() => {
    generate_data();
  }, refreshIntervalSec * 1000);
}

let refreshIntervalSec = 10;
let generateDataInterval;

const refreshForm = document.forms['refresh-form'];
const refreshIntervalInput = refreshForm['refresh-interval'];
const refreshSubmitButton = refreshForm.querySelector('button[type="submit"]');
const refreshButton = document.getElementById('refresh-button');

const notificationButton = document.getElementById('notification-button');
const notificationDropdown = document.getElementById('notification-dropdown');

document.addEventListener('DOMContentLoaded', () => {
  refreshIntervalInput.value = refreshIntervalSec;

  notificationButton.addEventListener('click', () => {
    notificationDropdown.classList.toggle('hidden');
  });

  document.addEventListener('click', (e) => {
    if (!notificationButton.contains(e.target) && !notificationDropdown.contains(e.target)) {
      notificationDropdown.classList.add('hidden');
    }
  });

  refreshForm.addEventListener('submit', (e) => {
    e.preventDefault();
    const newInterval = parseInt(refreshIntervalInput.value);
    if (!isNaN(newInterval) && newInterval > 0) {
      refreshIntervalSec = newInterval;
      startAutoRefresh();
    } else {
      alert('Введите корректное число секунд (> 0)');
    }
  });

  refreshButton.addEventListener('click', () => {
    generate_data();
    startAutoRefresh();
  });

  get_data();
  startAutoRefresh();
});
