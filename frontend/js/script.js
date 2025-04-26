function renderTable(devices, trafficStats, resourcesStats) {
  const tableContainer = document.getElementById('device-table');
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
          <td>${device.passwd_enabled}</td>
          <td>
            <button onclick="toggleVisibility('traffic-${device.id}')">Интерфейсы</button>
            <button onclick="toggleVisibility('resources-${device.id}')">Ресурсы</button>
          </td>
        </tr>
  
        <tr id="traffic-${device.id}" class="hidden">
          <td colspan="8">
            <table>
              <thead>
                <tr>
                  <th>Интерфейс</th>
                  <th>In Unicast</th>
                  <th>Out Unicast</th>
                  <th>In Multicast</th>
                  <th>Out Multicast</th>
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
  
        <tr id="resources-${device.id}" class="hidden">
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
                      <td>${r.cpu}</td>
                      <td>${r.disks.join('<br>')}</td>
                      <td>${r.memory}</td>
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
  const dropdown = document.getElementById('notification-dropdown');
  const button = document.getElementById('notification-button');

  dropdown.innerHTML = '';

  if (logMessages.length === 0) {
    dropdown.innerHTML = '<div class="notification-item">Нет ошибок.</div>';
  } else {
    logMessages.forEach(log => {
      const item = document.createElement('div');
      item.classList.add('notification-item');
      item.classList.add(log.status === 'success' ? 'notification-success' : 'notification-error');
      item.textContent = `${log.status === 'success' ? '✅' : '❌'} ${log.message}`;
      dropdown.appendChild(item);
    });
  }

  const errorCount = logMessages.filter(log => log.status !== 'success').length;
  button.textContent = `⚠️ (${errorCount})`;
}

fetch('../backend/data.php')
  .then(response => response.json())
  .then(data => {
    renderTable(data.devices, data.trafficStats, data.resourcesStats);
    showNotifications(data.logMessages);
  })
  .catch(error => {
    showNotifications([{ status: 'failure', message: `Ошибка загрузки данных: ${error.message}` }]);
  });

document.addEventListener('DOMContentLoaded', () => {
  const button = document.getElementById('notification-button');
  const dropdown = document.getElementById('notification-dropdown');

  button.addEventListener('click', () => {
    dropdown.classList.toggle('hidden');
  });

  document.addEventListener('click', (e) => {
    if (!button.contains(e.target) && !dropdown.contains(e.target)) {
      dropdown.classList.add('hidden');
    }
  });
});
